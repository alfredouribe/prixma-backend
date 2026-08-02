<?php

use App\Filament\Widgets\EngagementWidget;
use App\Filament\Widgets\EventsAndNotificationsWidget;
use App\Filament\Widgets\ModerationWidget;
use App\Filament\Widgets\PendingReportsWidget;
use App\Filament\Widgets\PlatformOverviewWidget;
use App\Filament\Widgets\RegistrationsChartWidget;
use App\Filament\Widgets\UserHealthWidget;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\Report;
use App\Models\Swipe;
use App\Models\User;
use App\Models\UserMatch;
use App\Services\PresenceService;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create(['role' => 'admin']);
});

/**
 * Extrae {label => value} de getStats() de un StatsOverviewWidget vía
 * reflection — necesitamos el número real que calcula cada Stat, no solo
 * el HTML renderizado (ver features/admin-dashboard/specs/tasks.md: "no
 * solo se renderiza sin error").
 */
function statsOf(string $widgetClass): array
{
    $widget = new $widgetClass();
    $method = new ReflectionMethod($widget, 'getStats');
    $method->setAccessible(true);

    return collect($method->invoke($widget))
        ->mapWithKeys(fn ($stat) => [$stat->getLabel() => $stat->getValue()])
        ->all();
}

// ---------------------------------------------------------------------------
// Guard — solo staff autenticado bajo el guard `admin` ve el dashboard
// ---------------------------------------------------------------------------

it('un usuario final (guard web) no puede acceder al dashboard', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertRedirect();
});

it('un admin autenticado ve el dashboard con éxito', function () {
    $this->actingAs($this->admin, 'admin')
        ->get('/admin')
        ->assertSuccessful();
});

// ---------------------------------------------------------------------------
// PlatformOverviewWidget
// ---------------------------------------------------------------------------

it('PlatformOverviewWidget calcula usuarios totales, en línea, activos hoy y nuevos hoy', function () {
    $this->actingAs($this->admin, 'admin');

    // Activo hoy vía swipe — también registrado hoy.
    $swiper = User::factory()->withCompletedOnboarding()->create();
    $swipeTarget = User::factory()->withCompletedOnboarding()->create();
    Swipe::create(['swiper_id' => $swiper->id, 'swiped_id' => $swipeTarget->id, 'direction' => 'like']);

    // El mismo swiper también manda un mensaje hoy — debe contar una sola
    // vez en "activos hoy" (unique() sobre la unión de los 3 sets).
    // conversation_id explícito para no dejar que ConversationFactory cree
    // 2 usuarios nuevos por default y desajuste "Usuarios totales".
    $conversation1 = Conversation::factory()->betweenUsers($swiper, $swipeTarget)->create();
    Message::factory()->create(['conversation_id' => $conversation1->id, 'sender_id' => $swiper->id, 'created_at' => now()]);

    // Activo hoy vía mensaje, usuario distinto.
    $sender = User::factory()->withCompletedOnboarding()->create();
    $conversation2 = Conversation::factory()->betweenUsers($sender, $swipeTarget)->create();
    Message::factory()->create(['conversation_id' => $conversation2->id, 'sender_id' => $sender->id, 'created_at' => now()]);

    // Activo hoy vía RSVP, usuario distinto.
    $rsvpUser = User::factory()->withCompletedOnboarding()->create();
    EventRsvp::factory()->create(['user_id' => $rsvpUser->id, 'created_at' => now()]);

    // Usuario registrado hace 10 días, con un swipe de ayer — no cuenta ni
    // en "nuevos hoy" ni en "activos hoy".
    $oldUser = User::factory()->withCompletedOnboarding()->create(['created_at' => now()->subDays(10)]);
    $yesterdaySwipe = Swipe::create(['swiper_id' => $oldUser->id, 'swiped_id' => $swipeTarget->id, 'direction' => 'dislike']);
    $yesterdaySwipe->created_at = now()->subDay();
    $yesterdaySwipe->save();

    // "En línea ahora" — mockeamos PresenceService, no dependemos de Reverb real.
    $this->mock(PresenceService::class, function ($mock) use ($swiper, $sender) {
        $mock->shouldReceive('onlineUserIds')->once()->andReturn([$swiper->id, $sender->id]);
    });

    $stats = statsOf(PlatformOverviewWidget::class);

    // Total: swiper, swipeTarget, sender, rsvpUser, oldUser = 5
    expect($stats['Usuarios totales'])->toBe(5);
    expect($stats['En línea ahora'])->toBe(2);
    expect($stats['Activos hoy'])->toBe(3); // swiper, sender, rsvpUser
    expect($stats['Nuevos hoy'])->toBe(4); // todos menos $oldUser
});

it('PlatformOverviewWidget reporta cero cuando no hay actividad', function () {
    $this->actingAs($this->admin, 'admin');

    $this->mock(PresenceService::class, function ($mock) {
        $mock->shouldReceive('onlineUserIds')->once()->andReturn([]);
    });

    $stats = statsOf(PlatformOverviewWidget::class);

    expect($stats['Usuarios totales'])->toBe(0);
    expect($stats['En línea ahora'])->toBe(0);
    expect($stats['Activos hoy'])->toBe(0);
    expect($stats['Nuevos hoy'])->toBe(0);
});

// ---------------------------------------------------------------------------
// UserHealthWidget
// ---------------------------------------------------------------------------

it('UserHealthWidget calcula verificados, premium y suspendidos/baneados', function () {
    $this->actingAs($this->admin, 'admin');

    Profile::factory()->verified()->count(2)->create();
    Profile::factory()->pending()->create();
    Profile::factory()->create(); // unverified por default

    User::factory()->withCompletedOnboarding()->create(['is_premium' => true]);
    User::factory()->withCompletedOnboarding()->create(['is_premium' => true]);
    User::factory()->withCompletedOnboarding()->create(['is_premium' => false]);

    User::factory()->withCompletedOnboarding()->suspended()->create();
    User::factory()->withCompletedOnboarding()->banned()->create();
    User::factory()->withCompletedOnboarding()->create(['status' => 'active']);

    $stats = statsOf(UserHealthWidget::class);

    expect($stats['Verificados'])->toBe(2);
    expect($stats['Premium activos'])->toBe(2);
    expect($stats['Suspendidos/baneados'])->toBe(2);
});

// ---------------------------------------------------------------------------
// EngagementWidget
// ---------------------------------------------------------------------------

it('EngagementWidget calcula swipes, matches y mensajes de hoy', function () {
    $this->actingAs($this->admin, 'admin');

    [$a, $b, $c, $d] = User::factory()->withCompletedOnboarding()->count(4)->create();

    // 2 swipes hoy, 1 de ayer (no debe contar).
    Swipe::create(['swiper_id' => $a->id, 'swiped_id' => $b->id, 'direction' => 'like']);
    Swipe::create(['swiper_id' => $c->id, 'swiped_id' => $d->id, 'direction' => 'like']);
    $oldSwipe = Swipe::create(['swiper_id' => $b->id, 'swiped_id' => $c->id, 'direction' => 'like']);
    $oldSwipe->created_at = now()->subDay();
    $oldSwipe->save();

    // 1 match hoy, 1 de ayer.
    UserMatch::create(['user_id_1' => $a->id, 'user_id_2' => $b->id]);
    $oldMatch = UserMatch::create(['user_id_1' => $c->id, 'user_id_2' => $d->id]);
    $oldMatch->created_at = now()->subDay();
    $oldMatch->save();

    // 3 mensajes hoy, 1 de ayer, 1 soft-deleted hoy (no debe contar).
    Message::factory()->count(3)->create(['created_at' => now()]);
    Message::factory()->create(['created_at' => now()->subDay()]);
    $deletedToday = Message::factory()->create(['created_at' => now()]);
    $deletedToday->delete();

    $stats = statsOf(EngagementWidget::class);

    expect($stats['Swipes hoy'])->toBe(2);
    expect($stats['Matches hoy'])->toBe(1);
    expect($stats['Mensajes hoy'])->toBe(3);
});

// ---------------------------------------------------------------------------
// RegistrationsChartWidget
// ---------------------------------------------------------------------------

it('RegistrationsChartWidget agrupa registros por día en los últimos 30 días', function () {
    $this->actingAs($this->admin, 'admin');

    User::factory()->count(2)->create(['created_at' => now()]);
    User::factory()->create(['created_at' => now()->subDays(5)]);
    // Fuera de la ventana de 30 días — no debe aparecer en absoluto.
    User::factory()->create(['created_at' => now()->subDays(40)]);

    $widget = new RegistrationsChartWidget();
    $method = new ReflectionMethod($widget, 'getData');
    $method->setAccessible(true);
    $data = $method->invoke($widget);

    expect($data['labels'])->toHaveCount(30);

    $todayLabel = now()->format('d/m');
    $fiveDaysAgoLabel = now()->subDays(5)->format('d/m');

    $todayIndex = array_search($todayLabel, $data['labels']);
    $fiveDaysAgoIndex = array_search($fiveDaysAgoLabel, $data['labels']);

    expect($data['datasets'][0]['data'][$todayIndex])->toBe(2);
    expect($data['datasets'][0]['data'][$fiveDaysAgoIndex])->toBe(1);
    expect(array_sum($data['datasets'][0]['data']))->toBe(3); // el registro de hace 40 días queda fuera
});

// ---------------------------------------------------------------------------
// ModerationWidget
// ---------------------------------------------------------------------------

it('ModerationWidget calcula reportes pendientes y resueltos en los últimos 7 días', function () {
    $this->actingAs($this->admin, 'admin');

    Report::factory()->count(2)->create(['status' => 'pending']);
    Report::factory()->create(['status' => 'reviewed']);

    // Resuelto hace 3 días (updated_at como proxy) — cuenta.
    $recentResolved = Report::factory()->resolved()->create();
    $recentResolved->updated_at = now()->subDays(3);
    $recentResolved->save();

    // Resuelto hace 10 días — no cuenta.
    $oldResolved = Report::factory()->resolved()->create();
    $oldResolved->updated_at = now()->subDays(10);
    $oldResolved->save();

    $stats = statsOf(ModerationWidget::class);

    expect($stats['Reportes pendientes'])->toBe(2);
    expect($stats['Resueltos últimos 7 días'])->toBe(1);
});

it('ModerationWidget reporta cero pendientes cuando no hay ninguno', function () {
    $this->actingAs($this->admin, 'admin');

    Report::factory()->resolved()->create();

    $stats = statsOf(ModerationWidget::class);

    expect($stats['Reportes pendientes'])->toBe(0);
});

// ---------------------------------------------------------------------------
// PendingReportsWidget — tabla, últimos 5 pendientes
// ---------------------------------------------------------------------------

it('PendingReportsWidget solo muestra reportes pendientes, hasta 5, más recientes primero', function () {
    $this->actingAs($this->admin, 'admin');

    $reviewed = Report::factory()->create(['status' => 'reviewed']);
    $resolved = Report::factory()->resolved()->create();

    $pending = collect(range(1, 6))->map(function (int $i) {
        $report = Report::factory()->create(['status' => 'pending', 'created_at' => now()->subMinutes($i)]);

        return $report;
    });

    // El más reciente de los 6 (subMinutes(1)) debe estar; el más viejo
    // (subMinutes(6)) debe quedar fuera por el límite de 5.
    $mostRecent = $pending->first();
    $oldest = $pending->last();

    Livewire::test(PendingReportsWidget::class)
        ->assertCanSeeTableRecords([$mostRecent])
        ->assertCanNotSeeTableRecords([$oldest, $reviewed, $resolved]);
});

it('PendingReportsWidget enlaza cada fila a ReportResource', function () {
    $this->actingAs($this->admin, 'admin');

    $report = Report::factory()->create(['status' => 'pending']);

    $expectedUrl = \App\Filament\Resources\ReportResource::getUrl('view', ['record' => $report]);

    Livewire::test(PendingReportsWidget::class)
        ->assertTableActionHasUrl('view', $expectedUrl, $report);
});

// ---------------------------------------------------------------------------
// EventsAndNotificationsWidget
// ---------------------------------------------------------------------------

it('EventsAndNotificationsWidget calcula próximos eventos, asistentes confirmados y push enviados hoy', function () {
    $this->actingAs($this->admin, 'admin');

    Event::factory()->count(2)->create(['event_date' => now()->addDays(3)]);
    $pastEvent = Event::factory()->create(['event_date' => now()->subDays(3)]);

    $upcomingEvent = Event::factory()->create(['event_date' => now()->addDays(5)]);
    EventRsvp::factory()->going()->count(2)->create(['event_id' => $upcomingEvent->id]);
    EventRsvp::factory()->interested()->create(['event_id' => $upcomingEvent->id]); // no cuenta, no es "going"

    // RSVP "going" a un evento pasado — no debe contar.
    EventRsvp::factory()->going()->create(['event_id' => $pastEvent->id]);

    Notification::factory()->count(2)->create(['sent_at' => now()]);
    Notification::factory()->create(['sent_at' => null]); // nunca se envió, no cuenta
    Notification::factory()->create(['sent_at' => now()->subDay()]); // enviado ayer, no cuenta

    $stats = statsOf(EventsAndNotificationsWidget::class);

    expect($stats['Próximos eventos'])->toBe(3); // 2 + $upcomingEvent
    expect($stats['Asistentes confirmados'])->toBe(2);
    expect($stats['Push enviados hoy'])->toBe(2);
});
