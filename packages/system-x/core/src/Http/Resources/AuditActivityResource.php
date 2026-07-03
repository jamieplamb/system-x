<?php

namespace SystemX\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'correlation_id' => $this->correlation_id,
            'app' => $this->app,
            'window_id' => $this->window_id,
            'widget_id' => $this->widget_id,
            'event' => $this->event,
            'outcome' => $this->outcome,
            'created_at' => $this->created_at?->toIso8601String(),
            'changes' => collect($this->changes ?? [])->map(fn ($c): array => [
                'property' => $c->property,
                'old' => json_decode($c->old_value, true),
                'new' => json_decode($c->new_value, true),
            ])->all(),
        ];
    }
}
