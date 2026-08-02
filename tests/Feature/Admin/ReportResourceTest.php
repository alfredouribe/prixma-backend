<?php

use App\Filament\Resources\ReportResource\Pages\ListReports;
use App\Filament\Resources\ReportResource\Pages\ViewReport;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Profile;
use App\Models\Report;
use App\Models\User;
use App\Services\SafetyService;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create(['role' => 'admin']);
});

// ---------------------------------------------------------------------------
// Cola de reportes — tabla server-side
// ---------------------------------------------------------------------------

it('admin autenticado ve la lista de reportes', function () {
    $reports = Report::factory()->count(3)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords($reports);
});

it('el filtro por estado se resuelve en la query, no en el render del cliente', function () {
    $pending = Report::factory()->count(2)->create(['status' => 'pending']);
    $resolved = Report::factory()->resolved()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListReports::class)
        ->filterTable('status', 'pending')
        ->assertCanSeeTableRecords($pending)
        ->assertCanNotSeeTableRecords($resolved);
});

it('el filtro por razón se resuelve en la query', function () {
    $harassment = Report::factory()->count(2)->create(['reason' => 'harassment']);
    $fakeProfile = Report::factory()->count(2)->create(['reason' => 'fake_profile']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListReports::class)
        ->filterTable('reason', 'fake_profile')
        ->assertCanSeeTableRecords($fakeProfile)
        ->assertCanNotSeeTableRecords($harassment);
});

it('la búsqueda por nombre del reportante se resuelve en la query (SQL)', function () {
    $target = Report::factory()->create();
    $target->reporter->profile->update(['display_name' => 'Roberta Única']);

    $others = Report::factory()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListReports::class)
        ->searchTable('Roberta')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords($others);
});

it('la búsqueda por email del reportante se resuelve en la query', function () {
    $target = Report::factory()->create();
    $target->reporter->update(['email' => 'unico-reportante@prixma.app']);

    $others = Report::factory()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListReports::class)
        ->searchTable('unico-reportante@prixma.app')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords($others);
});

it('la búsqueda por nombre del reportado se resuelve en la query', function () {
    $target = Report::factory()->create();
    $target->reported->profile->update(['display_name' => 'Fernanda Distinguible']);

    $others = Report::factory()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListReports::class)
        ->searchTable('Fernanda')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords($others);
});

it('la búsqueda por email del reportado se resuelve en la query', function () {
    $target = Report::factory()->create();
    $target->reported->update(['email' => 'unico-reportado@prixma.app']);

    $others = Report::factory()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListReports::class)
        ->searchTable('unico-reportado@prixma.app')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords($others);
});

it('la búsqueda por estado en español mapea al valor crudo en la query', function () {
    $pending = Report::factory()->create(['status' => 'pending']);
    $reviewed = Report::factory()->reviewed()->create();
    $resolved = Report::factory()->resolved()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListReports::class)
        ->searchTable('pendiente')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$reviewed, $resolved]);

    Livewire::test(ListReports::class)
        ->searchTable('revisado')
        ->assertCanSeeTableRecords([$reviewed])
        ->assertCanNotSeeTableRecords([$pending, $resolved]);

    Livewire::test(ListReports::class)
        ->searchTable('resuelto')
        ->assertCanSeeTableRecords([$resolved])
        ->assertCanNotSeeTableRecords([$pending, $reviewed]);
});

it('un estado desconocido en el buscador no rompe la tabla ni trae resultados por esa columna', function () {
    $reports = Report::factory()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListReports::class)
        ->searchTable('zzz-estado-inexistente')
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords($reports)
        ->assertCountTableRecords(0);
});

it('ordena por defecto de más antiguo a más reciente (FIFO)', function () {
    $older = Report::factory()->create(['created_at' => now()->subDays(2)]);
    $newer = Report::factory()->create(['created_at' => now()->subDay()]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
});

// ---------------------------------------------------------------------------
// Detalle — transiciones de estado (delegan a SafetyService)
// ---------------------------------------------------------------------------

it('admin puede marcar un reporte pendiente como revisado', function () {
    $report = Report::factory()->create(['status' => 'pending']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->callAction('markReviewed');

    expect($report->fresh()->status)->toBe('reviewed');
});

it('admin puede marcar un reporte como resuelto', function () {
    $report = Report::factory()->create(['status' => 'pending']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->callAction('markResolved');

    expect($report->fresh()->status)->toBe('resolved');
});

it('un reporte resuelto puede volver a marcarse como revisado', function () {
    $report = Report::factory()->resolved()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->callAction('markReviewed');

    expect($report->fresh()->status)->toBe('reviewed');
});

it('no se puede marcar como revisado un reporte que ya está revisado', function () {
    $report = Report::factory()->reviewed()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertActionHidden('markReviewed');
});

it('no se puede marcar como resuelto un reporte que ya está resuelto', function () {
    $report = Report::factory()->resolved()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertActionHidden('markResolved');
});

it('marcar como resuelto no modifica el status del usuario reportado — fuera de alcance', function () {
    $report = Report::factory()->create(['status' => 'pending']);
    $reportedUser = $report->reported;
    $originalStatus = $reportedUser->status;

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->callAction('markResolved');

    expect($reportedUser->fresh()->status)->toBe($originalStatus);
});

// ---------------------------------------------------------------------------
// Banear al usuario reportado — delega en SafetyService::banReportedUser()
// (ver features/safety/specs/plan.md → "Banear desde un reporte"). Los
// efectos secundarios del ban (tokens, correo, etc.) ya se prueban a nivel
// de Service en tests/Feature/Safety/SafetyTest.php — aquí solo se cubre el
// wiring de Filament: visibilidad, argumentos correctos, autorización.
// ---------------------------------------------------------------------------

it('admin puede banear al usuario reportado desde el detalle del reporte', function () {
    $report = Report::factory()->create(['status' => 'pending', 'reason' => 'harassment']);
    $reportedUser = $report->reported;

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->callAction('banUser');

    expect($reportedUser->fresh()->status)->toBe('banned');
    expect($report->fresh()->status)->toBe('resolved');
});

it('banear pasa la razón del reporte ya traducida a español al Service', function () {
    $report = Report::factory()->create(['status' => 'pending', 'reason' => 'fake_profile']);

    $this->actingAs($this->admin, 'admin');

    $capturedReasonLabel = null;

    // Partial mock (no mock()) — el infolist de ViewReport también llama a
    // getReportedUserPhotos()/getReportedUserMessagesForReportDate() del
    // mismo Service al renderizar, esas necesitan seguir ejecutando su
    // implementación real.
    $this->partialMock(SafetyService::class, function ($mock) use ($report, &$capturedReasonLabel) {
        $mock->shouldReceive('banReportedUser')
            ->once()
            ->andReturnUsing(function (Report $r, string $reasonLabel) use (&$capturedReasonLabel, $report) {
                $capturedReasonLabel = $reasonLabel;

                return $report;
            });
    });

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->callAction('banUser');

    expect($capturedReasonLabel)->toBe('Perfil falso');
});

it('no se puede banear a un usuario que ya está baneado — la acción no está visible', function () {
    $report = Report::factory()->create(['status' => 'pending']);
    $report->reported->update(['status' => 'banned']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertActionHidden('banUser');
});

it('un superadmin también puede banear', function () {
    $superadmin = Admin::factory()->superadmin()->create();
    $report = Report::factory()->create(['status' => 'pending']);
    $reportedUser = $report->reported;

    $this->actingAs($superadmin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->callAction('banUser');

    expect($reportedUser->fresh()->status)->toBe('banned');
});

// ---------------------------------------------------------------------------
// Autorización — admin y superadmin pueden, usuario final nunca
// ---------------------------------------------------------------------------

it('un superadmin también puede ver la lista y transicionar el estado', function () {
    $superadmin = Admin::factory()->superadmin()->create();
    $report = Report::factory()->create(['status' => 'pending']);

    $this->actingAs($superadmin, 'admin');

    Livewire::test(ListReports::class)->assertCanSeeTableRecords([$report]);

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->callAction('markReviewed');

    expect($report->fresh()->status)->toBe('reviewed');
});

it('un usuario final autenticado con el guard por defecto no puede acceder al listado de reportes', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($user) // guard 'web', nunca 'admin'
        ->get('/admin/reports')
        ->assertRedirect(); // nunca 200
});

it('sin autenticar, el listado de reportes redirige al login del panel', function () {
    $this->get('/admin/reports')->assertRedirect();
});

// ---------------------------------------------------------------------------
// Detalle — fotos del perfil reportado y mensajes del día del reporte
// ---------------------------------------------------------------------------

it('el detalle muestra las fotos del perfil reportado', function () {
    $report = Report::factory()->create();
    $photo = $report->reported->profile->photos()->create([
        'url'      => 'https://cdn.prixma.app/photos/foto-unica-test.jpg',
        'key'      => 'photos/foto-unica-test.jpg',
        'position' => 0,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertSuccessful()
        ->assertSeeHtml($photo->url);
});

it('el detalle muestra un estado vacío cuando el reportado no tiene fotos de perfil', function () {
    $report = Report::factory()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertSuccessful()
        ->assertSee('Sin fotos');
});

it('el detalle muestra los mensajes de ambos lados de la conversación del día del reporte', function () {
    $report = Report::factory()->create(['created_at' => now()]);
    $reportedUser = $report->reported;
    $otherUser = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $conversation = Conversation::factory()->betweenUsers($reportedUser, $otherUser)->create();

    Message::factory()->for($conversation)->create([
        'sender_id'  => $reportedUser->id,
        'content'    => 'Mensaje único del reportado',
        'created_at' => $report->created_at,
    ]);
    Message::factory()->for($conversation)->create([
        'sender_id'  => $otherUser->id,
        'content'    => 'Mensaje único de la otra persona',
        'created_at' => $report->created_at,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertSuccessful()
        ->assertSee('Mensaje único del reportado')
        ->assertSee('Mensaje único de la otra persona');
});

it('el detalle distingue quién envió cada mensaje, reportado vs. la otra persona', function () {
    $report = Report::factory()->create(['created_at' => now()]);
    $reportedUser = $report->reported;
    $reportedUser->profile->update(['display_name' => 'Reportado Único']);

    $otherUser = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $otherUser->profile->update(['display_name' => 'Otra Persona Única']);

    $conversation = Conversation::factory()->betweenUsers($reportedUser, $otherUser)->create();

    Message::factory()->for($conversation)->create([
        'sender_id'  => $reportedUser->id,
        'content'    => 'Hola',
        'created_at' => $report->created_at,
    ]);
    Message::factory()->for($conversation)->create([
        'sender_id'  => $otherUser->id,
        'content'    => 'Respuesta',
        'created_at' => $report->created_at,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertSuccessful()
        ->assertSee('Reportado Único (reportado)')
        ->assertSee('Otra Persona Única');
});

it('el detalle incluye mensajes de más de una conversación si el reportado tuvo actividad en ambas ese día', function () {
    $report = Report::factory()->create(['created_at' => now()]);
    $reportedUser = $report->reported;
    $userA = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $userB = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $conversationA = Conversation::factory()->betweenUsers($reportedUser, $userA)->create();
    $conversationB = Conversation::factory()->betweenUsers($reportedUser, $userB)->create();

    Message::factory()->for($conversationA)->create([
        'sender_id'  => $reportedUser->id,
        'content'    => 'Mensaje en conversación A',
        'created_at' => $report->created_at,
    ]);
    Message::factory()->for($conversationB)->create([
        'sender_id'  => $reportedUser->id,
        'content'    => 'Mensaje en conversación B',
        'created_at' => $report->created_at,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertSuccessful()
        ->assertSee('Mensaje en conversación A')
        ->assertSee('Mensaje en conversación B');
});

it('el detalle NO muestra mensajes de un día distinto al del reporte', function () {
    $report = Report::factory()->create(['created_at' => now()]);
    $reportedUser = $report->reported;
    $otherUser = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $conversation = Conversation::factory()->betweenUsers($reportedUser, $otherUser)->create();

    Message::factory()->for($conversation)->create([
        'sender_id'  => $reportedUser->id,
        'content'    => 'Mensaje de otro día, no debe aparecer',
        'created_at' => $report->created_at->copy()->subDays(3),
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertSuccessful()
        ->assertDontSee('Mensaje de otro día, no debe aparecer')
        ->assertSee('Sin mensajes ese día');
});

it('el detalle NO muestra mensajes eliminados (soft delete)', function () {
    $report = Report::factory()->create(['created_at' => now()]);
    $reportedUser = $report->reported;
    $otherUser = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $conversation = Conversation::factory()->betweenUsers($reportedUser, $otherUser)->create();

    $deleted = Message::factory()->for($conversation)->create([
        'sender_id'  => $reportedUser->id,
        'content'    => 'Mensaje borrado, no debe aparecer',
        'created_at' => $report->created_at,
    ]);
    $deleted->delete();

    Message::factory()->for($conversation)->create([
        'sender_id'  => $reportedUser->id,
        'content'    => 'Mensaje visible del mismo día',
        'created_at' => $report->created_at,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertSuccessful()
        ->assertDontSee('Mensaje borrado, no debe aparecer')
        ->assertSee('Mensaje visible del mismo día');
});

it('el detalle respeta el límite de 20 mensajes, mostrando los más recientes del día', function () {
    $report = Report::factory()->create(['created_at' => now()]);
    $reportedUser = $report->reported;
    $otherUser = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $conversation = Conversation::factory()->betweenUsers($reportedUser, $otherUser)->create();

    // 25 mensajes el mismo día, cada uno con contenido único y un minuto de
    // diferencia, para poder identificar cuáles son "los 5 más viejos" (deben
    // quedar excluidos) y cuál es el más reciente (debe estar presente).
    for ($i = 0; $i < 25; $i++) {
        Message::factory()->for($conversation)->create([
            'sender_id'  => $reportedUser->id,
            'content'    => "Mensaje número {$i}",
            'created_at' => $report->created_at->copy()->addMinutes($i),
        ]);
    }

    $messages = app(SafetyService::class)->getReportedUserMessagesForReportDate($report->fresh());

    expect($messages)->toHaveCount(20);
    expect($messages->pluck('content')->all())->not->toContain('Mensaje número 0');
    expect($messages->pluck('content')->all())->toContain('Mensaje número 24');

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertSuccessful()
        ->assertDontSee('Mensaje número 0')
        ->assertSee('Mensaje número 24');
});
