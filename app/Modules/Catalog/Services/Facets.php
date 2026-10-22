<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Asana;
use App\Modules\Catalog\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Facets
{
    public const DIMENSIONS = ['effect', 'anatomy', 'position', 'difficulty'];

    public const LABELS = ['effect' => 'Эффект / Направление практики', 'anatomy' => 'Зона тела / Анатомия', 'position' => 'Положение тела', 'difficulty' => 'Уровень сложности'];

    public const VALUES = [
        'effect' => [
            'balance' => 'Баланс', 'energizing' => 'Бодрящая', 'restorative' => 'Восстановительная и компенсационная',
            'flexibility' => 'Гибкость и растяжка', 'detox' => 'Детокс', 'dynamic' => 'Динамичная', 'grounding' => 'Заземление',
            'meditation' => 'Медитация', 'calming' => 'Мягкая / Успокаивающая', 'forward_fold' => 'Наклон вперёд',
            'foundation' => 'Основа', 'inversion' => 'Перевёрнутые асаны', 'backbend' => 'Прогибы', 'hip_opening' => 'Раскрытие бёдер',
            'chest_opening' => 'Раскрытие груди и сердца', 'relaxation' => 'Расслабление', 'strength' => 'Сила', 'twist' => 'Скручивания (скрутки)',
        ],
        'anatomy' => [
            'hips_glutes' => 'Бёдра и ягодицы (общая)', 'adductors' => 'Растяжка приводящих мышц', 'hip_flexors' => 'Растяжка сгибателей бедра',
            'quadriceps' => 'Растяжка квадрицепсов', 'hamstrings' => 'Растяжка подколенных сухожилий', 'inner_thigh' => 'Внутренняя поверхность бедра',
            'whole_body' => 'Всё тело', 'chest' => 'Грудь', 'calves_ankles' => 'Икры и лодыжки', 'core' => 'Кор (пресс и центр тела)',
            'shoulders_arms' => 'Плечи и руки (включая силу рук и раскрытие плеч)', 'wrists' => 'Запястья',
            'spine_back' => 'Позвоночник и спина (включая верх спины, поясницу и подвижность позвоночника)', 'neck' => 'Шея',
            'side_body' => 'Боковая линия (боковое вытяжение)',
        ],
        'position' => [
            'arm_balance' => 'Баланс на руках', 'lunge' => 'Выпады', 'kneeling' => 'На коленях', 'floor_reclining' => 'На полу / полулёжа',
            'squat' => 'Присед', 'seated' => 'Сидя', 'standing' => 'Стоя (включая широкую стойку и устойчивость)',
            'prone' => 'Лёжа на животе', 'supine' => 'Лёжа на спине',
        ],
        'difficulty' => ['beginner' => 'Начальный', 'intermediate' => 'Средний', 'advanced' => 'Продвинутый'],
    ];

    /** Stable keys, never names, distinguish system values from personal categories. */
    public function ensure(string $workspaceId): void
    {
        if (Category::where('workspace_id', $workspaceId)->whereNotNull('system_key')->count() === 45) {
            return;
        }
        $rows = [];
        foreach (self::VALUES as $dimension => $values) {
            $order = 0;
            foreach ($values as $key => $name) {
                $rows[] = ['id' => (string) Str::uuid(), 'workspace_id' => $workspaceId,
                    'name' => $name, 'parent_id' => null, 'dimension' => $dimension, 'system_key' => $dimension.'.'.$key,
                    'sort_order' => $order++, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        // Concurrent provisioning is safe and never rewrites existing assignments/UUIDs.
        DB::table('catalog_categories')->insertOrIgnore($rows);
    }

    public function validate(array $assignments, string $workspaceId, bool $lock = false): array
    {
        $rules = ['facets' => ['array:'.implode(',', self::DIMENSIONS)]];
        foreach (self::DIMENSIONS as $dimension) {
            $rules['facets.'.$dimension] = ['sometimes', 'array', 'list', 'max:'.count(self::VALUES[$dimension])];
            $rules['facets.'.$dimension.'.*'] = ['required', 'uuid', 'distinct:ignore_case'];
        }
        Validator::make(['facets' => $assignments], $rules)->validate();
        foreach ($assignments as $dimension => &$ids) {
            $ids = array_map('strtolower', $ids);
            if ($dimension === 'difficulty' && count($ids) > 1) {
                throw ValidationException::withMessages(['facet_assignments.difficulty' => 'Выберите не больше одного уровня сложности.']);
            }
            $query = Category::where('workspace_id', $workspaceId)->where('dimension', $dimension)->whereNotNull('system_key')->whereIn('id', $ids)->orderBy('id');
            if (($lock ? $query->sharedLock() : $query)->get(['id'])->count() !== count($ids)) {
                throw ValidationException::withMessages(['facet_assignments.'.$dimension => 'Признак недоступен или принадлежит другой группе.']);
            }
        }
        unset($ids);

        return $assignments;
    }

    /** Missing groups remain untouched, empty arrays clear just that dimension. */
    public function sync(Asana $asana, array $assignments): void
    {
        $assignments = $this->validate($assignments, $asana->workspace_id, true);
        foreach ($assignments as $dimension => $ids) {
            $previous = $asana->categories()->where('dimension', $dimension)->pluck('catalog_categories.id')->all();
            $asana->categories()->detach(array_values(array_diff($previous, $ids)));
            $asana->categories()->syncWithoutDetaching($ids);
        }
    }

    public function syncLegacy(Asana $asana, array $ids): void
    {
        $ids = array_values(array_unique(array_map('strtolower', $ids)));
        $available = Category::where('workspace_id', $asana->workspace_id)->whereNull('dimension')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id']);
        if ($available->count() !== count($ids)) {
            throw ValidationException::withMessages(['category_ids' => 'Личная категория недоступна; системные признаки передаются отдельно.']);
        }
        $previous = $asana->categories()->whereNull('dimension')->pluck('catalog_categories.id')->all();
        $asana->categories()->detach(array_values(array_diff($previous, $ids)));
        $asana->categories()->syncWithoutDetaching($ids);
    }
}
