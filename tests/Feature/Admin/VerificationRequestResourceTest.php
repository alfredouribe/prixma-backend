<?php

use App\Filament\Resources\VerificationRequestResource\Pages\ListVerificationRequests;
use App\Filament\Resources\VerificationRequestResource\Pages\ViewVerificationRequest;
use App\Models\Admin;
use App\Models\VerificationRequest;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3_identity');
    $this->admin = Admin::factory()->create(['role' => 'admin']);
});

// ---------------------------------------------------------------------------
// Cola de solicitudes — tabla server-side
// ---------------------------------------------------------------------------

it('admin autenticado ve la cola de solicitudes', function () {
    $requests = VerificationRequest::factory()->count(3)->create(['status' => 'pending']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListVerificationRequests::class)
        ->assertCanSeeTableRecords($requests);
});

it('el filtro por estado se resuelve en la query, no en el render del cliente', function () {
    $pending = VerificationRequest::factory()->count(2)->create(['status' => 'pending']);
    $rejected = VerificationRequest::factory()->rejected()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListVerificationRequests::class)
        ->filterTable('status', 'pending')
        ->assertCanSeeTableRecords($pending)
        ->assertCanNotSeeTableRecords($rejected);
});

it('la búsqueda por nombre de usuario se resuelve en la query (SQL), no filtrando en PHP/JS', function () {
    $target = VerificationRequest::factory()->create();
    $target->profile->update(['display_name' => 'Roberta Única']);

    $others = VerificationRequest::factory()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListVerificationRequests::class)
        ->searchTable('Roberta')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords($others);
});

it('la búsqueda por email del usuario también se resuelve en la query', function () {
    $target = VerificationRequest::factory()->create();
    $target->profile->user->update(['email' => 'unico@prixma.app']);

    $others = VerificationRequest::factory()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListVerificationRequests::class)
        ->searchTable('unico@prixma.app')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords($others);
});

it('ordena por defecto de más antigua a más reciente (FIFO)', function () {
    $older = VerificationRequest::factory()->create(['created_at' => now()->subDays(2)]);
    $newer = VerificationRequest::factory()->create(['created_at' => now()->subDay()]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListVerificationRequests::class)
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
});

// ---------------------------------------------------------------------------
// Detalle — aprobar / rechazar (delegan al VerificationService)
// ---------------------------------------------------------------------------

it('admin puede aprobar una solicitud pendiente desde el detalle', function () {
    $request = VerificationRequest::factory()->create(['status' => 'pending']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewVerificationRequest::class, ['record' => $request->id])
        ->callAction('approve');

    expect($request->fresh()->status)->toBe('approved');
    expect($request->fresh()->reviewed_by)->toBe((string) $this->admin->id);
    expect($request->fresh()->reviewed_at)->not->toBeNull();
    expect($request->profile->fresh()->verification_status)->toBe('verified');
});

it('rechazar sin motivo falla la validación de la acción', function () {
    $request = VerificationRequest::factory()->create(['status' => 'pending']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewVerificationRequest::class, ['record' => $request->id])
        ->callAction('reject', data: ['rejection_reason' => ''])
        ->assertHasActionErrors(['rejection_reason']);

    expect($request->fresh()->status)->toBe('pending');
});

it('admin puede rechazar con motivo', function () {
    $request = VerificationRequest::factory()->create(['status' => 'pending']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewVerificationRequest::class, ['record' => $request->id])
        ->callAction('reject', data: ['rejection_reason' => 'La foto no es legible.']);

    expect($request->fresh())
        ->status->toBe('rejected')
        ->rejection_reason->toBe('La foto no es legible.');
    expect($request->profile->fresh()->verification_status)->toBe('rejected');
});

it('no se puede aprobar/rechazar una solicitud que ya fue revisada', function () {
    $request = VerificationRequest::factory()->approved()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewVerificationRequest::class, ['record' => $request->id])
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');
});
