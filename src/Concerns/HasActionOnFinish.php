<?php

namespace Konnco\FilamentImport\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;

trait HasActionOnFinish
{
    protected bool|Closure $runOnSuccess = false;

    protected bool|Closure $runOnFail = false;

    public function runOnSuccess(bool|Closure $fn): static
    {
        $this->runOnSuccess = $fn;

        return $this;
    }

    public function doRunOnSuccess()
    {
        if ($this->runOnSuccess !== false) {
            return ($this->runOnSuccess)();
        }

        return null;
    }

    public function runOnFail(bool|Closure $fn): static
    {
        $this->runOnFail = $fn;

        return $this;
    }

    public function doRunOnFail()
    {
        if ($this->runOnFail !== false) {
            return ($this->runOnFail)();
        }

        return null;
    }
}