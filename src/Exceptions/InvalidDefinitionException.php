<?php

namespace Aviato\Laravel\Exceptions;

use LogicException;

/** A plugin definition is incomplete or a handler returned a value of the wrong shape. */
class InvalidDefinitionException extends LogicException {}
