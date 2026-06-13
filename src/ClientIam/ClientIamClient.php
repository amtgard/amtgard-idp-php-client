<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam;

use Amtgard\IAM\Allowance\Claim;
use Amtgard\IAM\Allowance\Policy;
use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\OrkServices;
use Amtgard\IAM\PolicyFactory;
use Amtgard\IdpClient\ClientIam\Http\Psr18ClientIamHttpClient;
use Amtgard\IdpClient\ClientIam\Iam\IntegratorOrnRegistrar;
use Amtgard\IdpClient\ClientIam\Model\PolicyClaimList;
use Amtgard\IdpClient\ClientIam\Model\ServiceFormat;
use Amtgard\IdpClient\ClientIam\Model\ServiceFormatRequest;
use Amtgard\IdpClient\ClientIam\Model\UserMetadata;
use Amtgard\IdpClient\ClientIam\Model\UserMetadataRequest;
use Amtgard\IdpClient\ClientIam\Validation\PolicyClaimValidator;
use Amtgard\IdpClient\ClientIam\Validation\ServiceFormatValidator;
use Amtgard\IdpClient\ClientIam\Validation\UserMetadataValidator;
use Amtgard\IdpClient\Config\IdpClientEnvironment;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\IdpConfigurationException;
use Amtgard\IdpClient\Iam\OrnBootstrap;
use Amtgard\IdpClient\Iam\OrnWireFormat;
use Amtgard\IdpClient\Iam\ServiceFormatParser;

final class ClientIamClient
{
    private ?ServiceFormat $cachedServiceFormat = null;

    /** @var list<\Amtgard\IAM\OrkServices|string>|null */
    private ?array $cachedFormatSlots = null;

    public function __construct(
        private readonly Psr18ClientIamHttpClient $http,
        private readonly ?string $offlineIamService = null,
        /** @var list<string>|null */
        private readonly ?array $offlineServiceFormat = null,
    ) {}

    public static function requireSecret(IdpClientEnvironment $environment): void
    {
        $secret = $environment->clientSecret();
        if ($secret === null || $secret === '') {
            throw new IdpConfigurationException(['IDP_CLIENT_SECRET']);
        }
    }

    /** @phpstan-impure */
    public function getServiceFormat(): ServiceFormat
    {
        if ($this->cachedServiceFormat === null) {
            $this->cachedServiceFormat = $this->http->getServiceFormat();
            $this->cachedFormatSlots = ServiceFormatParser::parseList($this->cachedServiceFormat->serviceFormat);
            if ($this->cachedServiceFormat->iamService !== null && $this->cachedServiceFormat->iamService !== '') {
                IntegratorOrnRegistrar::register(
                    $this->cachedServiceFormat->iamService,
                    $this->cachedFormatSlots,
                );
            }
        }

        return $this->cachedServiceFormat;
    }

    public function createServiceFormat(ServiceFormatRequest $request): void
    {
        ServiceFormatValidator::validate($request);
        $this->http->createServiceFormat($request->serviceFormat);
        $this->invalidateFormatCache();
    }

    public function replaceServiceFormat(ServiceFormatRequest $request): void
    {
        ServiceFormatValidator::validate($request);
        $this->http->replaceServiceFormat($request->serviceFormat);
        $this->invalidateFormatCache();
    }

    /**
     * @param array<string, int|string|null> $segments
     */
    public function composeClaim(array $segments, string $resource): Claim
    {
        $prefix = $this->requireIamService();
        $schema = $this->requireServiceFormatSlots();
        IntegratorOrnRegistrar::register($prefix, $schema);

        if (strcasecmp($prefix, OrkServices::Idp->value) === 0) {
            OrnBootstrap::register();
        }

        $orn = OrnWireFormat::composeFullOrn($prefix, $schema, $segments, $resource);

        return ClaimFactory::createOrn($orn);
    }

    public function addPolicyClaim(string $idpUserId, Claim $claim): void
    {
        $iamService = $this->requireIamService();
        $format = $this->requireServiceFormatSlots();
        PolicyClaimValidator::validateClaim($idpUserId, $claim, $iamService, $format);

        $parts = OrnWireFormat::fromClaim($claim);
        $this->http->addPolicyClaim($idpUserId, $parts->provisos, $parts->resource);
    }

    public function addPolicyClaimFromOrn(string $idpUserId, string $fullOrn): void
    {
        $iamService = $this->requireIamService();
        $format = $this->requireServiceFormatSlots();
        IntegratorOrnRegistrar::register($iamService, $format);

        $claim = ClaimFactory::createOrn($fullOrn);
        PolicyClaimValidator::validateClaim($idpUserId, $claim, $iamService, $format);

        $parts = OrnWireFormat::fromClaim($claim);
        $this->http->addPolicyClaim($idpUserId, $parts->provisos, $parts->resource);
    }

    public function deletePolicyClaim(string $idpUserId, Claim $claim): void
    {
        $iamService = $this->requireIamService();
        $format = $this->requireServiceFormatSlots();
        PolicyClaimValidator::validateClaim($idpUserId, $claim, $iamService, $format);

        $parts = OrnWireFormat::fromClaim($claim);
        $this->http->deletePolicyClaim($idpUserId, $parts->provisos, $parts->resource);
    }

    public function listPolicyClaims(string $idpUserId): PolicyClaimList
    {
        PolicyClaimValidator::validateIdpUserId($idpUserId);

        return $this->http->listPolicyClaims($idpUserId);
    }

    public function policyFromStoredClaims(PolicyClaimList $claims): Policy
    {
        $iamService = $this->requireIamService();
        $format = $this->requireServiceFormatSlots();
        IntegratorOrnRegistrar::register($iamService, $format);

        $orns = [];
        foreach ($claims->claims as $claim) {
            $orns[] = $claim->fullOrn();
        }

        try {
            return PolicyFactory::fromOrn($orns);
        } catch (\Throwable $exception) {
            throw new ClientIamException(
                ErrorCode::ClientIamInvalidOrn,
                sprintf('Invalid stored policy claim ORN: %s', $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    public function putUserMetadata(UserMetadataRequest $request): void
    {
        UserMetadataValidator::validate($request);
        $this->http->putUserMetadata(
            $request->idpUserId,
            $request->loginId,
            $request->metadata,
            $request->encoding,
        );
    }

    public function getUserMetadata(string $idpUserId, int $loginId): UserMetadata
    {
        PolicyClaimValidator::validateIdpUserId($idpUserId);
        if ($loginId <= 0) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'login_id is required.',
            );
        }

        return $this->http->getUserMetadata($idpUserId, $loginId);
    }

    public function deleteUserMetadata(string $idpUserId, int $loginId): void
    {
        PolicyClaimValidator::validateIdpUserId($idpUserId);
        if ($loginId <= 0) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'login_id is required.',
            );
        }

        $this->http->deleteUserMetadata($idpUserId, $loginId);
    }

    public function iamService(): ?string
    {
        if ($this->cachedServiceFormat !== null && $this->cachedServiceFormat->iamService !== null) {
            return $this->cachedServiceFormat->iamService;
        }

        return $this->offlineIamService;
    }

    /**
     * @return list<\Amtgard\IAM\OrkServices|string>
     */
    public function serviceFormatSlots(): array
    {
        return $this->requireServiceFormatSlots();
    }

    private function requireIamService(): string
    {
        $service = $this->offlineIamService;
        if ($service === null || $service === '') {
            $service = $this->cachedServiceFormat?->iamService;
        }

        if ($service === null || $service === '') {
            $this->getServiceFormat();
            $service = $this->cachedServiceFormat?->iamService;
        }

        if (($service === null || $service === '') && $this->cachedServiceFormat?->isDefault === true) {
            $service = 'Idp';
        }

        if ($service === null || $service === '') {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'Client is not configured with an iam_service prefix.',
            );
        }

        return $service;
    }

    /**
     * @return list<\Amtgard\IAM\OrkServices|string>
     */
    private function requireServiceFormatSlots(): array
    {
        if ($this->cachedFormatSlots !== null) {
            return $this->cachedFormatSlots;
        }

        if ($this->offlineServiceFormat !== null) {
            /** @var list<\Amtgard\IAM\OrkServices|string> $parsed */
            $parsed = ServiceFormatParser::parseList($this->offlineServiceFormat);
            $this->cachedFormatSlots = $parsed;

            return $parsed;
        }

        $this->getServiceFormat();

        if ($this->cachedFormatSlots === null) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'Service format is not available; call getServiceFormat() or set IDP_IAM_SERVICE_FORMAT.',
            );
        }

        return $this->cachedFormatSlots;
    }

    private function invalidateFormatCache(): void
    {
        $this->cachedServiceFormat = null;
        $this->cachedFormatSlots = null;
    }
}
