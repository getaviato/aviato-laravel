<?php

namespace Aviato\Laravel;

use Aviato\Laravel\Context\ActionContext;
use Aviato\Laravel\Context\ActionResult;
use Aviato\Laravel\Context\Caller;
use Aviato\Laravel\Context\Context;
use Aviato\Laravel\Context\FormContext;
use Aviato\Laravel\Context\FormResolution;
use Aviato\Laravel\Context\HookContext;
use Aviato\Laravel\Context\HookOutcome;
use Aviato\Laravel\Data\DataClient;
use Aviato\Laravel\Definitions\ActionDefinition;
use Aviato\Laravel\Definitions\ChartDefinition;
use Aviato\Laravel\Definitions\ComputedFieldDefinition;
use Aviato\Laravel\Definitions\DatasourceDefinition;
use Aviato\Laravel\Definitions\HookDefinition;
use Aviato\Laravel\Definitions\RelationHint;
use Aviato\Laravel\Definitions\SearchDefinition;
use Aviato\Laravel\Definitions\SegmentDefinition;
use Aviato\Laravel\Definitions\WriteOverrideDefinition;
use Aviato\Laravel\Enums\HookOperation;
use Aviato\Laravel\Enums\HookTiming;
use Aviato\Laravel\Exceptions\InvalidDefinitionException;
use Aviato\Laravel\Exceptions\RpcException;
use Aviato\Laravel\Integrations\IntegrationPlugin;
use Aviato\Laravel\Integrations\IntegrationWidget;
use Aviato\Laravel\Support\Signing;
use Aviato\Laravel\Support\Structs;
use Aviato\Plugin\V1\ActionDefinition as ProtoActionDefinition;
use Aviato\Plugin\V1\ChartDefinition as ProtoChartDefinition;
use Aviato\Plugin\V1\ComputedFieldDefinition as ProtoComputedFieldDefinition;
use Aviato\Plugin\V1\HookDefinition as ProtoHookDefinition;
use Aviato\Plugin\V1\LayoutDefinition;
use Aviato\Plugin\V1\Manifest;
use Aviato\Plugin\V1\SearchDefinition as ProtoSearchDefinition;
use Aviato\Plugin\V1\SegmentDefinition as ProtoSegmentDefinition;
use Aviato\Plugin\V1\SummaryDefinition;
use Aviato\Plugin\V1\WidgetDefinition;
use Aviato\Plugin\V1\WriteOverrideDefinition as ProtoWriteOverrideDefinition;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use StandardWebhooks\Webhook;

/**
 * Extends an Aviato connector with actions, computed fields, hooks, segments, search, charts and
 * custom datasources.
 * Use it through the `Aviato` facade, usually from a service provider's `boot` method.
 */
class Plugin
{
    public const PROTOCOL_VERSION = 1;

    public const SDK_NAME = 'getaviato/laravel';

    public const SDK_VERSION = '0.6.0';

    /** @var array<string, ActionDefinition> */
    private array $actions = [];

    /** @var array<string, ComputedFieldDefinition> */
    private array $computedFields = [];

    /** @var list<HookDefinition> */
    private array $hooks = [];

    /** @var array<string, SegmentDefinition> */
    private array $segments = [];

    /** @var array<string, SearchDefinition> */
    private array $searches = [];

    /** @var array<string, WriteOverrideDefinition> */
    private array $writeOverrides = [];

    /** @var array<string, ChartDefinition> */
    private array $charts = [];

    /** @var array<string, array<string, mixed>> */
    private array $summaries = [];

    /** @var array<string, array<string, mixed>> */
    private array $layouts = [];

    /** @var array<string, WidgetDefinition> */
    private array $widgets = [];

    /** @var list<RelationHint> */
    private array $relationHints = [];

    /** @var (Closure(): list<RelationHint>)|null */
    private ?Closure $relationHintResolver = null;

    private readonly Datasources $datasources;

    /** @param Closure(Caller): DataClient $dataClientFactory */
    public function __construct(private Closure $dataClientFactory)
    {
        $this->datasources = new Datasources;
    }

    // ------------------------------------------------------------------------------ registry

    public function action(string $collection, string $name): ActionDefinition
    {
        return $this->actions["{$collection}.{$name}"] = new ActionDefinition($collection, $name);
    }

    /** @param array<string, mixed> $document Declarative Aviato UI v1 document. */
    public function summary(string $collection, array $document): self
    {
        $this->summaries[$collection] = $document;

        return $this;
    }

    /**
     * Declares how a collection looks in the back-office: label, icon, fieldOrder, fields (label,
     * description, hidden, readOnly, widget, currency), searchFields, defaultSort and relations. It
     * replaces the layout edited in the dashboard, which then shows it read-only. Registering a
     * collection again replaces its layout; the connector ignores an invalid one.
     *
     * @param  array<string, mixed>  $layout
     */
    public function layout(string $collection, array $layout): self
    {
        $this->layouts[$collection] = $layout;

        return $this;
    }

    /** Atomically install a snapshot of a typed provider integration. */
    public function use(IntegrationPlugin $integration): self
    {
        $definitions = array_map(static fn (IntegrationWidget $widget): WidgetDefinition => new WidgetDefinition([
            'collection' => $widget->collection, 'name' => $widget->name, 'title' => $widget->title,
            'provider' => $widget->provider, 'resource' => $widget->resource,
            'binding_field' => $widget->binding->field, 'binding_relation' => $widget->binding->relation,
            'credential_env' => $widget->credentialEnv, 'read_only' => $widget->readOnly, 'account' => $widget->account,
        ]), $integration->widgets());
        foreach ($definitions as $definition) {
            $this->widgets[$definition->getCollection()."\0".$definition->getName()] = $definition;
        }

        return $this;
    }

    public function computedField(string $collection, string $name): ComputedFieldDefinition
    {
        return $this->computedFields["{$collection}.{$name}"] = new ComputedFieldDefinition($collection, $name);
    }

    public function hook(string $collection, HookTiming|string $timing, HookOperation|string $operation): HookDefinition
    {
        $hook = new HookDefinition(
            $collection,
            is_string($timing) ? HookTiming::from($timing) : $timing,
            is_string($operation) ? HookOperation::from($operation) : $operation,
        );
        $this->hooks[] = $hook;

        return $hook;
    }

    public function segment(string $collection, string $name): SegmentDefinition
    {
        return $this->segments["{$collection}.{$name}"] = new SegmentDefinition($collection, $name);
    }

    public function search(string $collection): SearchDefinition
    {
        return $this->searches[$collection] = new SearchDefinition($collection);
    }

    public function writeOverride(string $collection, string $field): WriteOverrideDefinition
    {
        return $this->writeOverrides["{$collection}.{$field}"] = new WriteOverrideDefinition($collection, $field);
    }

    public function chart(string $name, string $collection): ChartDefinition
    {
        return $this->charts[$name] = new ChartDefinition($name, $collection);
    }

    /**
     * Collections served by your code (an internal API, a SaaS, a file), browsed and edited like
     * database collections, under the same permissions and audit trail.
     */
    public function datasource(): DatasourceDefinition
    {
        return $this->datasources->add(new DatasourceDefinition);
    }

    /** The registered custom datasources, and the calls to their handlers. */
    public function datasources(): Datasources
    {
        return $this->datasources;
    }

    /**
     * Asks the connector to pull the changes of a replicated collection now, for example from a
     * webhook of the source. `$dataUrl` is the connector's Data API (`Caller::$dataUrl`,
     * `<connector URL>/plugin-data`). The request is signed with the plugin secret.
     *
     * @return array{collection: string, upserted: int, deleted: int, cursor: string}
     *
     * @throws RequestException when the connector answers with an error.
     */
    public function refreshReplica(string $collection, string $dataUrl): array
    {
        $secret = config('aviato.secret');
        if (! is_string($secret) || $secret === '') {
            throw new InvalidDefinitionException('The Aviato plugin secret is not configured (AVIATO_PLUGIN_SECRET).');
        }
        $body = '{}';
        $path = 'replicas/'.rawurlencode($collection).'/refresh';
        // Bound to this endpoint, so the connector cannot be handed this request as another one.
        $id = Signing::boundMessageId((string) Str::uuid(), 'POST', $path);
        $timestamp = time();
        $response = Http::withHeaders([
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => (new Webhook($secret))->sign($id, $timestamp, $body),
        ])
            ->accept('application/json')
            ->withBody($body, 'application/json')
            ->post(rtrim($dataUrl, '/').'/'.$path)
            ->throw();
        $result = $response->json();

        return [
            'collection' => is_array($result) && is_string($result['collection'] ?? null) ? $result['collection'] : $collection,
            'upserted' => is_array($result) && is_numeric($result['upserted'] ?? null) ? (int) $result['upserted'] : 0,
            'deleted' => is_array($result) && is_numeric($result['deleted'] ?? null) ? (int) $result['deleted'] : 0,
            'cursor' => is_array($result) && is_scalar($result['cursor'] ?? null) ? (string) $result['cursor'] : '',
        ];
    }

    /**
     * Declares a relation by hand (Eloquent relations are discovered automatically).
     *
     * @param  'belongsTo'|'hasMany'|'hasOne'  $kind
     * @param  array<array-key, string>|string  $foreignKey
     * @param  array<array-key, string>|string  $targetKey
     */
    public function relation(string $collection, string $name, string $kind, array|string $foreignKey, string $target, array|string $targetKey = 'id'): self
    {
        $this->relationHints[] = new RelationHint($collection, $name, $kind, array_values((array) $foreignKey), $target, array_values((array) $targetKey));

        return $this;
    }

    /** @param (Closure(): list<RelationHint>)|null $resolver */
    public function resolveRelationHintsUsing(?Closure $resolver): self
    {
        $this->relationHintResolver = $resolver;

        return $this;
    }

    /** @param Closure(Caller): DataClient $factory */
    public function resolveDataClientUsing(Closure $factory): self
    {
        $this->dataClientFactory = $factory;

        return $this;
    }

    /** @return list<RelationHint> */
    public function relationHints(): array
    {
        $discovered = $this->relationHintResolver !== null ? ($this->relationHintResolver)() : [];

        return [...$this->relationHints, ...$discovered];
    }

    public function manifest(): Manifest
    {
        return new Manifest([
            'widgets' => array_map(static fn (WidgetDefinition $widget): WidgetDefinition => clone $widget, array_values($this->widgets)),
            'summaries' => array_map(fn (string $collection): SummaryDefinition => new SummaryDefinition([
                'collection' => $collection,
                'document' => Structs::fromArray($this->summaries[$collection]),
            ]), array_keys($this->summaries)),
            'layouts' => array_map(fn (string $collection): LayoutDefinition => new LayoutDefinition([
                'collection' => $collection,
                'layout' => Structs::fromArray($this->layouts[$collection]),
            ]), array_keys($this->layouts)),
            'protocol_version' => self::PROTOCOL_VERSION,
            'sdk_name' => self::SDK_NAME,
            'sdk_version' => self::SDK_VERSION,
            'actions' => array_map(fn (ActionDefinition $action): ProtoActionDefinition => new ProtoActionDefinition(array_filter([
                'collection' => $action->collection,
                'name' => $action->name,
                'scope' => $action->scope->toProto(),
                'description' => $action->description,
                'form_schema' => $action->formSchema !== null ? Structs::fromArray($action->formSchema) : null,
                'form_ui_schema' => $action->formUiSchema !== null ? Structs::fromArray($action->formUiSchema) : null,
                'dynamic_form' => $action->resolveForm !== null,
                'generates_file' => $action->generatesFile,
            ], fn (mixed $value): bool => $value !== null)), array_values($this->actions)),
            'computed_fields' => array_map(fn (ComputedFieldDefinition $field): ProtoComputedFieldDefinition => new ProtoComputedFieldDefinition([
                'collection' => $field->collection,
                'name' => $field->name,
                'type' => $field->type,
                'dependencies' => $field->dependencies,
                'emulate_filter_sort' => $field->emulateFilterSort,
                'enum_values' => $field->enumValues,
            ]), array_values($this->computedFields)),
            'hooks' => array_map(fn (HookDefinition $hook): ProtoHookDefinition => new ProtoHookDefinition([
                'collection' => $hook->collection,
                'timing' => $hook->timing->toProto(),
                'operation' => $hook->operation->toProto(),
            ]), $this->hooks),
            'segments' => array_map(fn (SegmentDefinition $segment): ProtoSegmentDefinition => new ProtoSegmentDefinition([
                'collection' => $segment->collection,
                'name' => $segment->name,
            ]), array_values($this->segments)),
            'searches' => array_map(fn (SearchDefinition $search): ProtoSearchDefinition => new ProtoSearchDefinition([
                'collection' => $search->collection,
                'replace' => $search->replace,
            ]), array_values($this->searches)),
            'charts' => array_map(fn (ChartDefinition $chart): ProtoChartDefinition => new ProtoChartDefinition([
                'name' => $chart->name,
                'collection' => $chart->collection,
            ]), array_values($this->charts)),
            'write_overrides' => array_map(fn (WriteOverrideDefinition $write): ProtoWriteOverrideDefinition => new ProtoWriteOverrideDefinition([
                'collection' => $write->collection,
                'field' => $write->field,
            ]), array_values($this->writeOverrides)),
            'relation_hints' => array_map(fn (RelationHint $hint) => $hint->toProto(), $this->relationHints()),
            'datasource' => ! $this->datasources->isEmpty(),
        ]);
    }

    // ------------------------------------------------------------------------------ invocation

    /**
     * @param  list<string>  $recordIds
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $values
     */
    public function executeAction(string $collection, string $name, Caller $caller, array $recordIds = [], ?array $filter = null, string $segment = '', string $search = '', array $values = []): ActionResult
    {
        $action = $this->requireAction($collection, $name);
        if ($action->execute === null) {
            throw new InvalidDefinitionException("Action {$collection}.{$name} has no execute handler.");
        }
        $result = ($action->execute)(new ActionContext($caller, $this->dataClientFactory, $collection, $recordIds, $filter, $segment, $search, $values));
        if ($result === null) {
            return ActionResult::success();
        }
        if (! $result instanceof ActionResult) {
            throw new InvalidDefinitionException("Action {$collection}.{$name} must return an ActionResult, got ".get_debug_type($result).'.');
        }

        return $result;
    }

    /**
     * @param  list<string>  $recordIds
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $values
     */
    public function resolveForm(string $collection, string $name, Caller $caller, array $recordIds = [], ?array $filter = null, string $segment = '', string $search = '', array $values = [], string $changedField = ''): FormResolution
    {
        $action = $this->requireAction($collection, $name);
        if ($action->resolveForm === null) {
            return new FormResolution($action->formSchema, $action->formUiSchema);
        }
        $resolved = ($action->resolveForm)(new FormContext($caller, $this->dataClientFactory, $collection, $recordIds, $filter, $segment, $search, $values, $changedField));
        if (is_array($resolved)) {
            $form = is_array($resolved['form'] ?? null) ? $resolved['form'] : [];
            $resolved = new FormResolution(
                is_array($form['schema'] ?? null) ? $form['schema'] : null,
                is_array($form['uiSchema'] ?? null) ? $form['uiSchema'] : null,
                is_array($resolved['values'] ?? null) ? $resolved['values'] : null,
            );
        }
        $resolved ??= new FormResolution;
        if (! $resolved instanceof FormResolution) {
            throw new InvalidDefinitionException("The form of {$collection}.{$name} must resolve to a FormResolution or an array, got ".get_debug_type($resolved).'.');
        }

        // Without a replacement schema the static form stays in place.
        return $resolved->schema !== null
            ? $resolved
            : new FormResolution($action->formSchema, $action->formUiSchema, $resolved->values);
    }

    /**
     * @param  list<string>  $fields
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>> One object of computed values per record, in order.
     */
    public function computeFields(string $collection, array $fields, array $records, Caller $caller): array
    {
        $values = array_fill(0, count($records), []);
        foreach ($fields as $name) {
            $field = $this->computedFields["{$collection}.{$name}"] ?? null;
            if ($field === null) {
                throw RpcException::notFound("Unknown computed field {$collection}.{$name}");
            }
            if ($field->compute === null) {
                throw new InvalidDefinitionException("Computed field {$collection}.{$name} has no compute handler.");
            }
            $computed = ($field->compute)($records, $this->context($caller));
            if (! is_iterable($computed)) {
                throw new InvalidDefinitionException("Computed field {$collection}.{$name} must return one value per record.");
            }
            $computed = array_values(is_array($computed) ? $computed : iterator_to_array($computed, false));
            foreach ($values as $index => $record) {
                $values[$index][$name] = $computed[$index] ?? null;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed> The column values to write instead of the virtual field.
     */
    public function writeField(string $collection, string $field, mixed $value, array $record, Caller $caller): array
    {
        $write = $this->writeOverrides["{$collection}.{$field}"] ?? null;
        if ($write === null) {
            throw RpcException::notFound("No write override for {$collection}.{$field}");
        }
        if ($write->write === null) {
            throw new InvalidDefinitionException("Write override {$collection}.{$field} has no write handler.");
        }

        return $this->object(($write->write)($value, $record, $this->context($caller)), "Write override {$collection}.{$field}");
    }

    /** @return array{filter: array<string, mixed>}|array{ids: list<string>} */
    public function resolveSegment(string $collection, string $name, Caller $caller): array
    {
        $segment = $this->segments["{$collection}.{$name}"] ?? null;
        if ($segment === null) {
            throw RpcException::notFound("Unknown segment {$collection}.{$name}");
        }
        if ($segment->resolve === null) {
            throw new InvalidDefinitionException("Segment {$collection}.{$name} has no resolve handler.");
        }
        $resolved = ($segment->resolve)($this->context($caller));
        if (is_array($resolved) && is_array($resolved['filter'] ?? null)) {
            return ['filter' => $this->object($resolved['filter'], "Segment {$collection}.{$name}")];
        }
        if (is_array($resolved) && is_array($resolved['ids'] ?? null)) {
            return ['ids' => array_values(array_map(fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $resolved['ids']))];
        }
        throw new InvalidDefinitionException("Segment {$collection}.{$name} must return ['filter' => [...]] or ['ids' => [...]].");
    }

    /**
     * @param  list<string>  $recordIds
     * @param  array<string, mixed>|null  $values
     */
    public function runHooks(string $collection, HookTiming $timing, HookOperation $operation, Caller $caller, array $recordIds = [], ?array $values = null, string $action = ''): HookOutcome
    {
        foreach ($this->hooks as $hook) {
            if ($hook->collection !== $collection || $hook->timing !== $timing || $hook->operation !== $operation || $hook->run === null) {
                continue;
            }
            $outcome = ($hook->run)(new HookContext($caller, $this->dataClientFactory, $collection, $timing, $operation, $recordIds, $values, $action));
            if (is_array($outcome)) {
                $outcome = new HookOutcome(
                    is_string($outcome['reject'] ?? null) ? $outcome['reject'] : null,
                    is_array($outcome['values'] ?? null) ? $outcome['values'] : null,
                );
            }
            if ($outcome !== null && ! $outcome instanceof HookOutcome) {
                throw new InvalidDefinitionException("Hook {$timing->value} {$operation->value} on {$collection} must return a HookOutcome, an array or null.");
            }
            if ($outcome === null) {
                continue;
            }
            if ($outcome->rejected()) {
                return $outcome;
            }
            $values = $outcome->values ?? $values;
        }

        return new HookOutcome(values: $values);
    }

    /** @return array<string, mixed> */
    public function searchFilter(string $collection, string $query, Caller $caller): array
    {
        $search = $this->searches[$collection] ?? null;
        if ($search === null) {
            throw RpcException::notFound("No search for {$collection}");
        }
        if ($search->resolve === null) {
            throw new InvalidDefinitionException("Search on {$collection} has no resolve handler.");
        }

        return $this->object(($search->resolve)($query, $this->context($caller)), "Search on {$collection}");
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed> A Vega-Lite specification.
     */
    public function computeChart(string $name, ?array $filter, Caller $caller): array
    {
        $chart = $this->charts[$name] ?? null;
        if ($chart === null) {
            throw RpcException::notFound("Unknown chart {$name}");
        }
        if ($chart->compute === null) {
            throw new InvalidDefinitionException("Chart {$name} has no compute handler.");
        }

        return $this->object(($chart->compute)($filter, $this->context($caller)), "Chart {$name}");
    }

    private function requireAction(string $collection, string $name): ActionDefinition
    {
        return $this->actions["{$collection}.{$name}"] ?? throw RpcException::notFound("Unknown action {$collection}.{$name}");
    }

    private function context(Caller $caller): Context
    {
        return new Context($caller, $this->dataClientFactory);
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $what): array
    {
        if (! is_array($value)) {
            throw new InvalidDefinitionException("{$what} must return an array, got ".get_debug_type($value).'.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
