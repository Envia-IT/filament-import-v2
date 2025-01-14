<?php

namespace Konnco\FilamentImport;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Konnco\FilamentImport\Actions\ImportField;
use Konnco\FilamentImport\Concerns\HasActionMutation;
use Konnco\FilamentImport\Concerns\HasActionOnFinish;
use Konnco\FilamentImport\Concerns\HasActionUniqueField;
use Konnco\FilamentImport\Concerns\HasTemporaryDisk;
use Maatwebsite\Excel\Concerns\Importable;

class Import
{
    use Importable;
    use HasActionMutation;
    use HasActionOnFinish;
    use HasActionUniqueField;
    use HasTemporaryDisk;

    protected string $spreadsheet;

    protected Collection $fields;

    protected array $formSchemas;

    protected string|Model $model;

    protected string $disk = 'local';

    protected bool $shouldSkipHeader = false;

    protected bool $shouldMassCreate = true;

    protected bool $shouldHandleBlankRows = false;

    protected ?Closure $handleRecordCreation = null;
    protected ?int $rowsLimit = null;

    public static function make(string $spreadsheetFilePath): self
    {
        return (new self)
            ->spreadsheet($spreadsheetFilePath);
    }

    public function fields(Collection $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    public function formSchemas(array $formSchemas): static
    {
        $this->formSchemas = $formSchemas;

        return $this;
    }

    public function spreadsheet($spreadsheet): static
    {
        $this->spreadsheet = $spreadsheet;

        return $this;
    }

    public function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function disk($disk = 'local'): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function skipHeader(bool $shouldSkipHeader): static
    {
        $this->shouldSkipHeader = $shouldSkipHeader;

        return $this;
    }

    public function massCreate($shouldMassCreate = true): static
    {
        $this->shouldMassCreate = $shouldMassCreate;

        return $this;
    }

    public function handleBlankRows($shouldHandleBlankRows = false): static
    {
        $this->shouldHandleBlankRows = $shouldHandleBlankRows;

        return $this;
    }

    public function rowsLimit(?int $rows = null): static
    {
        $this->rowsLimit = $rows;

        return $this;
    }

    /**
     * @throws \Exception
     */
    public function getSpreadsheetData(): Collection
    {
        $data = $this->toCollection($this->temporaryDiskIsRemote() ? $this->spreadsheet : new UploadedFile(Storage::disk($this->disk)->path($this->spreadsheet), $this->spreadsheet))
            ->first()
            ->skip((int) $this->shouldSkipHeader);

        if ($this->rowsLimit && $data->count() > $this->rowsLimit) {
            throw new \Exception('The import file is too large. The maximum number of rows allowed is ' . $this->rowsLimit . '.');
        }

        if (! $this->shouldHandleBlankRows) {
            return $data;
        }

        return $data->filter(function ($row) {
            return $row->filter()->isNotEmpty();
        });
    }

    public function validated($data, $rules, $customMessages, $line)
    {
        $validator = Validator::make($data, $rules, $customMessages);

        try {
            if ($validator->fails()) {
                Notification::make()
                    ->danger()
                    ->title(trans('filament-import::actions.import_failed_title'))
                    ->body(trans('filament-import::validators.message', ['line' => $line, 'error' => $validator->errors()->first()]))
                    ->persistent()
                    ->send();

                return false;
            }
        } catch (\Exception $e) {
            return $data;
        }

        return $data;
    }

    public function handleRecordCreation(?Closure $closure): static
    {
        $this->handleRecordCreation = $closure;

        return $this;
    }

    public function execute()
    {
        try {
            $importSuccess = true;
            $skipped = 0;
            DB::transaction(function () use (&$importSuccess, &$skipped) {
                foreach ($this->getSpreadsheetData() as $line => $row) {
                    $prepareInsert = collect([]);
                    $rules = [];
                    $validationMessages = [];

                    foreach (Arr::dot($this->fields) as $key => $value) {
                        $field = $this->formSchemas[$key];
                        $fieldValue = $value;

                        if ($field instanceof ImportField) {
                            // check if field is optional
                            if (!$field->isRequired() && blank(@$row[$value])) {
                                continue;
                            }

                            $fieldValue = $field->doMutateBeforeCreate($row[$value], collect($row)) ?? $row[$value];
                            $rules[$key] = $field->getValidationRules();
                            if (count($field->getCustomValidationMessages())) {
                                $validationMessages[$key] = $field->getCustomValidationMessages();
                            }
                        }

                        $prepareInsert[$key] = $fieldValue;
                    }

                    $prepareInsert = $this->validated(data: Arr::undot($prepareInsert), rules: $rules, customMessages: $validationMessages, line: $line + 1);

                    if (!$prepareInsert) {
                        DB::rollBack();
                        $importSuccess = false;

                        break;
                    }

                    $prepareInsert = $this->doMutateBeforeCreate($prepareInsert);

                    if ($this->uniqueField !== false) {
                        if (is_null($prepareInsert[$this->uniqueField] ?? null)) {
                            DB::rollBack();
                            $importSuccess = false;

                            break;
                        }

                        $exists = (new $this->model)->where($this->uniqueField, $prepareInsert[$this->uniqueField] ?? null)->first();
                        if ($exists instanceof $this->model) {
                            $skipped++;

                            continue;
                        }
                    }

                    if (!$this->handleRecordCreation) {
                        if (!$this->shouldMassCreate) {
                            $model = (new $this->model)->fill($prepareInsert);
                            $model = tap($model, function ($instance) {
                                $instance->save();
                            });
                        } else {
                            $model = $this->model::create($prepareInsert);
                        }
                    } else {
                        $closure = $this->handleRecordCreation;
                        $model = $closure($prepareInsert);
                    }

                    $this->doMutateAfterCreate($model, $prepareInsert);
                }
            });

            if ($importSuccess) {
                $this->doRunOnSuccess();
                Notification::make()
                    ->success()
                    ->title(trans('filament-import::actions.import_succeeded_title'))
                    ->body(trans('filament-import::actions.import_succeeded', ['count' => count($this->getSpreadsheetData()), 'skipped' => $skipped]))
                    ->persistent()
                    ->send();
            }

            if (!$importSuccess) {
                $this->doRunOnFail();
                Notification::make()
                    ->danger()
                    ->title(trans('filament-import::actions.import_failed_title'))
                    ->body(trans('filament-import::actions.import_failed'))
                    ->persistent()
                    ->send();
            }
        } catch (\Exception $e) {
            $this->doRunOnFail();
            Notification::make()
                ->danger()
                ->title('Error importing file')
                ->body($e->getMessage())
                ->send();
        }
    }
}