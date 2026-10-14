<?php

namespace Aviato\Laravel\Agent;

use Aviato\Laravel\Connector\ConnectorEnvironment;

/** @deprecated Use the Connector namespace. */
class_alias(ConnectorEnvironment::class, __NAMESPACE__.'\\AgentEnvironment');
