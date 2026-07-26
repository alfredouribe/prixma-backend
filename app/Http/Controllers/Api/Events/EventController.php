<?php

namespace App\Http\Controllers\Api\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\RsvpEventRequest;
use App\Http\Resources\EventResource;
use App\Services\EventService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController extends Controller
{
    public function __construct(private readonly EventService $eventService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['category', 'date_from', 'date_to', 'lat', 'lng', 'radius', 'per_page', 'page']);
        $events = $this->eventService->list($request->user(), $filters);

        return EventResource::collection($events);
    }

    public function show(Request $request, string $uuid): EventResource
    {
        $event = $this->eventService->find($request->user(), $uuid);

        return new EventResource($event);
    }

    public function rsvp(RsvpEventRequest $request, string $uuid): EventResource
    {
        $event = $this->eventService->rsvp($request->user(), $uuid, $request->input('status'));

        return new EventResource($event);
    }
}
