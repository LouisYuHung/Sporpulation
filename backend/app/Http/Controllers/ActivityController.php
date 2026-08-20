<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    /**
     * 即將開始的活動，依開始時間由近到遠排序，可另以運動或行政區篩選。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'sport_id' => ['nullable', 'integer', 'exists:sports,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $activities = Activity::query()
            ->upcoming()
            ->with(self::relations($request))
            ->when(
                isset($filters['sport_id']),
                fn (Builder $query) => $query->where('sport_id', $filters['sport_id'])
            )
            ->when(
                isset($filters['district_id']),
                fn (Builder $query) => $query->where('district_id', $filters['district_id'])
            )
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ActivityResource::collection($activities);
    }

    /**
     * 建立一個活動。主辦人不會被自動報名；若他也想下場，就跟其他人一樣自行報名。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sport_id' => ['required', 'integer', 'exists:sports,id'],
            'district_id' => ['required', 'integer', 'exists:districts,id'],
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'location' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'between:1,999'],
        ]);

        $activity = $request->user()->hostedActivities()->create($data);

        return $this->show($request, $activity)->response()->setStatusCode(201);
    }

    public function show(Request $request, Activity $activity): ActivityResource
    {
        return new ActivityResource($activity->load(self::relations($request)));
    }

    /**
     * 每份活動 payload 都會帶上的關聯。
     *
     * `registrations` 會限縮成只查呼叫者自己的紀錄，讓 payload 不必再發第二個請求
     * 就能回報他自己的報名狀態。訪客則什麼都拿不到，回應中也不會出現
     * `my_registration`。
     *
     * @return array<int|string, mixed>
     */
    public static function relations(Request $request): array
    {
        $relations = ['host', 'sport', 'district.city'];

        if ($user = $request->user()) {
            $relations['registrations'] = fn ($query) => $query->where('user_id', $user->id);
        }

        return $relations;
    }
}
