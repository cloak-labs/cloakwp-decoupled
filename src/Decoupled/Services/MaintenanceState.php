<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Services;

final class MaintenanceState
{
  private bool $revalidationPaused = false;
  private bool $restApiLocked = false;

  public function pauseRevalidation(bool $paused): void
  {
    $this->revalidationPaused = $paused;
  }

  public function isRevalidationPaused(): bool
  {
    return $this->revalidationPaused;
  }

  public function lockRestApi(bool $locked): void
  {
    $this->restApiLocked = $locked;
  }

  public function isRestApiLocked(): bool
  {
    return $this->restApiLocked;
  }
}
