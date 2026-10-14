<?php

namespace Aviato\Laravel\Agent;

use Aviato\Laravel\Connector\ConnectorBinary;

/** @deprecated Use the Connector namespace. */
class_alias(ConnectorBinary::class, __NAMESPACE__.'\\AgentBinary');
