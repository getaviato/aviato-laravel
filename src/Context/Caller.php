<?php

namespace Aviato\Laravel\Context;

use Aviato\Plugin\V1\Caller as ProtoCaller;

/** Who is calling and on what. Sent by the connector with every call. */
final readonly class Caller
{
    public function __construct(
        public string $userId,
        public string $name = '',
        /** human | agent | api_key */
        public string $actorType = 'human',
        /** admin | editor | viewer | custom role name */
        public string $role = '',
        public string $organizationId = '',
        public string $projectId = '',
        public string $environment = '',
        public string $requestId = '',
        /** Short-lived token for Data API calls made while handling this call. */
        public string $invocationToken = '',
        /** Base URL of the connector's Data API. */
        public string $dataUrl = '',
    ) {}

    public static function fromProto(ProtoCaller $caller): self
    {
        return new self(
            userId: $caller->getUserId(),
            name: $caller->getName(),
            actorType: $caller->getActorType(),
            role: $caller->getRole(),
            organizationId: $caller->getOrganizationId(),
            projectId: $caller->getProjectId(),
            environment: $caller->getEnvironment(),
            requestId: $caller->getRequestId(),
            invocationToken: $caller->getInvocationToken(),
            dataUrl: $caller->getDataUrl(),
        );
    }

    /** @param array<string, string> $attributes */
    public static function fake(array $attributes = []): self
    {
        return new self(...array_merge([
            'userId' => 'u_test',
            'name' => 'Test User',
            'actorType' => 'human',
            'role' => 'admin',
            'organizationId' => 'o_test',
            'projectId' => 'p_test',
            'environment' => 'test',
            'requestId' => 'r_test',
            'invocationToken' => 'test-token',
            'dataUrl' => 'http://aviato-connector.test/data',
        ], $attributes));
    }

    public function isHuman(): bool
    {
        return $this->actorType === 'human';
    }
}
