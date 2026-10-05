<?php

namespace Aviato\Laravel\Definitions;

use Aviato\Laravel\Enums\ActionScope;
use Closure;

/**
 * A custom action on a collection.
 *
 *     Aviato::action('customers', 'Refund last invoice')
 *         ->single()
 *         ->form(['type' => 'object', 'properties' => ['reason' => ['type' => 'string']]])
 *         ->execute(fn (ActionContext $context) => $context->success('Refunded'));
 */
final class ActionDefinition
{
    public ActionScope $scope = ActionScope::Single;

    public string $description = '';

    /** @var array<string, mixed>|null */
    public ?array $formSchema = null;

    /** @var array<string, mixed>|null */
    public ?array $formUiSchema = null;

    public bool $generatesFile = false;

    public ?Closure $resolveForm = null;

    public ?Closure $execute = null;

    public function __construct(public readonly string $collection, public readonly string $name) {}

    public function scope(ActionScope|string $scope): self
    {
        $this->scope = is_string($scope) ? ActionScope::from($scope) : $scope;

        return $this;
    }

    /** Runs on one record. */
    public function single(): self
    {
        return $this->scope(ActionScope::Single);
    }

    /** Runs on a selection of records. */
    public function bulk(): self
    {
        return $this->scope(ActionScope::Bulk);
    }

    /** Runs on the collection, with the current filters, segment and search. */
    public function global(): self
    {
        return $this->scope(ActionScope::Global);
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $schema  JSON Schema describing the form data.
     * @param  array<string, mixed>|null  $uiSchema  JSON Forms UI schema (layout, rules).
     */
    public function form(array $schema, ?array $uiSchema = null): self
    {
        $this->formSchema = $schema;
        $this->formUiSchema = $uiSchema;

        return $this;
    }

    /**
     * Recomputes the form when it opens and after each change. The handler receives a
     * FormContext and returns a FormResolution, an array like
     * `['values' => [...], 'form' => ['schema' => [...], 'uiSchema' => [...]]]`, or null.
     *
     * @param  callable|class-string  $handler
     */
    public function resolveForm(callable|string $handler): self
    {
        $this->resolveForm = Handler::wrap($handler);

        return $this;
    }

    /** The action returns a file, downloaded instead of shown. */
    public function generatesFile(bool $generatesFile = true): self
    {
        $this->generatesFile = $generatesFile;

        return $this;
    }

    /**
     * The handler receives an ActionContext and returns an ActionResult (null means success).
     *
     * @param  callable|class-string  $handler
     */
    public function execute(callable|string $handler): self
    {
        $this->execute = Handler::wrap($handler);

        return $this;
    }
}
