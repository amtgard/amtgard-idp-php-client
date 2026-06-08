<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Exception;

final class IdpConfigurationException extends \RuntimeException
{
    /**
     * @param list<string> $missingVariables
     */
    public function __construct(
        private readonly array $missingVariables,
    ) {
        parent::__construct(sprintf(
            'Missing required IDP environment variables: %s',
            implode(', ', $missingVariables),
        ));
    }

    /** @return list<string> */
    public function missingVariables(): array
    {
        return $this->missingVariables;
    }
}
