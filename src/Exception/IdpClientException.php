<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Exception;

class IdpClientException extends \RuntimeException
{
    public function __construct(
        private readonly ErrorCode $errorCode,
        string $message,
        private readonly ?string $idpError = null,
        private readonly ?string $idpErrorDescription = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public function idpError(): ?string
    {
        return $this->idpError;
    }

    public function idpErrorDescription(): ?string
    {
        return $this->idpErrorDescription;
    }

    public function developerHint(): string
    {
        return sprintf(
            'See README error code %s (%s) for fix instructions.',
            $this->errorCode->value,
            $this->errorCode->readmeAnchor(),
        );
    }
}
