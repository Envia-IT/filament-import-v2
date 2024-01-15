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
        $closure = $this->runOnSuccess;

        return $closure();
    }

    public function runOnFail(bool|Closure $fn): static
    {
        $this->runOnFail = $fn;

        return $this;
    }

    public function doRunOnFail()
    {
        $closure = $this->runOnFail;

        return $closure();
    }
}