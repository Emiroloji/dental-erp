<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Access\Support\Module;
use App\Domain\Assistant\Contracts\QueryInterpreter;
use App\Domain\Assistant\Exceptions\InterpreterException;
use App\Domain\Assistant\Exceptions\QueryLimitException;
use App\Domain\Assistant\Interpreters\UnconfiguredQueryInterpreter;
use App\Domain\Assistant\Models\AssistantQuery;
use App\Domain\Assistant\Support\AssistantQueryStatus;
use App\Domain\Assistant\Support\InterpreterContext;
use App\Domain\Assistant\Support\QueryIntent;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Doğal dille rapor sorgulama (Faz 4 — Aşama 25):
 * soru → (limit kontrolü) → yapay zekâ yalnızca sorguyu yapılandırır →
 * sonuç doğrulanır → sistem kendi raporunu kullanıcının kapsamında çalıştırır.
 */
class NaturalQueryService
{
    /** Bağlamdaki her seçenek listesinin üst sınırı (istem boyutu için). */
    private const MAX_OPTIONS = 200;

    public function __construct(
        private readonly QueryInterpreter $interpreter,
        private readonly IntentExecutor $executor,
    ) {}

    /**
     * @return array{query: AssistantQuery, intent: QueryIntent, answer: ?array}
     *
     * @throws QueryLimitException
     * @throws InterpreterException
     */
    public function ask(User $user, string $question): array
    {
        $question = trim($question);

        if ($this->interpreter instanceof UnconfiguredQueryInterpreter) {
            // Dış çağrı yok, limit de harcanmaz.
            $this->interpreter->interpret($question, $this->context($user));
        }

        $query = $this->reserve($user, $question);
        $context = $this->context($user);

        try {
            $raw = $this->interpreter->interpret($question, $context);
        } catch (InterpreterException $e) {
            $query->update(['status' => AssistantQueryStatus::Failed, 'error' => $e->getMessage()]);

            throw $e;
        }

        $intent = QueryIntent::fromInterpreter($raw, $context);

        if (! $intent->understood) {
            $query->update(['status' => AssistantQueryStatus::NotUnderstood, 'intent' => $intent->toArray()]);

            return ['query' => $query, 'intent' => $intent, 'answer' => null];
        }

        $answer = $this->executor->run($intent, $user);
        $query->update(['status' => AssistantQueryStatus::Answered, 'intent' => $intent->toArray()]);

        return ['query' => $query, 'intent' => $intent, 'answer' => $answer];
    }

    /**
     * @return array{daily: int, monthly: int, daily_limit: int, monthly_limit: int}
     */
    public function usage(int $organizationId): array
    {
        $base = fn () => AssistantQuery::withoutGlobalScopes()->where('organization_id', $organizationId);

        return [
            'daily' => $base()->where('created_at', '>=', now()->startOfDay())->count(),
            'monthly' => $base()->where('created_at', '>=', now()->startOfMonth())->count(),
            'daily_limit' => (int) config('assistant.daily_limit'),
            'monthly_limit' => (int) config('assistant.monthly_limit'),
        ];
    }

    /**
     * Limit kontrolü ve kayıt tek işlemde; organizasyon satırı kilitlenir ki
     * eşzamanlı sorular limiti aşamasın. Sağlayıcı çağrısı kilit dışında yapılır.
     */
    private function reserve(User $user, string $question): AssistantQuery
    {
        return DB::transaction(function () use ($user, $question) {
            Organization::whereKey($user->organization_id)->lockForUpdate()->firstOrFail();

            $usage = $this->usage($user->organization_id);

            if ($usage['daily'] >= $usage['daily_limit']) {
                throw new QueryLimitException("Organizasyonunuzun günlük asistan sorgu limiti doldu ({$usage['daily_limit']}). Yarın tekrar deneyebilirsiniz.");
            }

            if ($usage['monthly'] >= $usage['monthly_limit']) {
                throw new QueryLimitException("Organizasyonunuzun aylık asistan sorgu limiti doldu ({$usage['monthly_limit']}).");
            }

            return AssistantQuery::create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'question' => $question,
                'provider' => $this->interpreter->name(),
                'status' => AssistantQueryStatus::Pending,
            ]);
        });
    }

    /**
     * Sağlayıcıya giden seçenekler: yalnızca kullanıcının Raporlar kapsamındaki
     * şube/depolar ile organizasyonun kategori ve tedarikçi adları.
     */
    private function context(User $user): InterpreterContext
    {
        $accessible = $user->accessibleBranchIds(Module::Reports);
        $option = fn ($model, string $name) => ['id' => (int) $model->id, 'name' => $name];

        return new InterpreterContext(
            today: now()->startOfDay(),
            branches: Branch::when($accessible !== null, fn ($query) => $query->whereIn('id', $accessible))
                ->orderBy('name')->limit(self::MAX_OPTIONS)->get(['id', 'name'])
                ->map(fn (Branch $branch) => $option($branch, $branch->name))->all(),
            warehouses: Warehouse::whereHas('branch')->inBranches($accessible)->with('branch')
                ->orderBy('name')->limit(self::MAX_OPTIONS)->get()
                ->map(fn (Warehouse $warehouse) => $option($warehouse, "{$warehouse->branch->name} — {$warehouse->name}"))->all(),
            categories: Category::orderBy('name')->limit(self::MAX_OPTIONS)->get(['id', 'name'])
                ->map(fn (Category $category) => $option($category, $category->name))->all(),
            suppliers: Supplier::orderBy('name')->limit(self::MAX_OPTIONS)->get(['id', 'name'])
                ->map(fn (Supplier $supplier) => $option($supplier, $supplier->name))->all(),
        );
    }
}
