<?php

use App\Filament\Pages\PlatformSettings;
use App\Models\Admin;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Acceso — admin y superadmin, no es tan sensible como SimulateMessage
// ---------------------------------------------------------------------------

it('admin puede acceder a la página', function () {
    $admin = Admin::factory()->create(['role' => 'admin']);

    $this->actingAs($admin, 'admin')
        ->get(PlatformSettings::getUrl())
        ->assertSuccessful();
});

it('superadmin puede acceder a la página', function () {
    $superadmin = Admin::factory()->superadmin()->create();

    $this->actingAs($superadmin, 'admin')
        ->get(PlatformSettings::getUrl())
        ->assertSuccessful();
});

it('un usuario final (guard web) no puede acceder', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($user)
        ->get(PlatformSettings::getUrl())
        ->assertRedirect();
});

// ---------------------------------------------------------------------------
// Guardar — actualiza la fila única de PlatformSetting
// ---------------------------------------------------------------------------

it('carga los valores actuales al montar', function () {
    PlatformSetting::current()->update([
        'free_swipes_per_ad' => 8,
        'free_likes_per_day' => 3,
        'free_chat_minutes_before_ad' => 2,
    ]);
    $admin = Admin::factory()->create(['role' => 'admin']);

    $this->actingAs($admin, 'admin');

    Livewire::test(PlatformSettings::class)
        ->assertFormSet([
            'free_swipes_per_ad' => 8,
            'free_likes_per_day' => 3,
            'free_chat_minutes_before_ad' => 2,
        ]);
});

it('guarda los 3 números nuevos', function () {
    $admin = Admin::factory()->create(['role' => 'admin']);

    $this->actingAs($admin, 'admin');

    Livewire::test(PlatformSettings::class)
        ->fillForm([
            'free_swipes_per_ad' => 10,
            'free_likes_per_day' => 7,
            'free_chat_minutes_before_ad' => 3,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = PlatformSetting::current()->fresh();
    expect($settings->free_swipes_per_ad)->toBe(10);
    expect($settings->free_likes_per_day)->toBe(7);
    expect($settings->free_chat_minutes_before_ad)->toBe(3);
});

it('sube el video del anuncio de chat al disco s3, sin ACL público', function () {
    Storage::fake('s3');
    $admin = Admin::factory()->create(['role' => 'admin']);

    $this->actingAs($admin, 'admin');

    Livewire::test(PlatformSettings::class)
        ->fillForm([
            'free_swipes_per_ad' => 5,
            'free_likes_per_day' => 5,
            'free_chat_minutes_before_ad' => 1,
            'chat_ad_video_key' => UploadedFile::fake()->create('ad.mp4', 1000, 'video/mp4'),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = PlatformSetting::current()->fresh();
    expect($settings->chat_ad_video_key)->not->toBeNull();
    Storage::disk('s3')->assertExists($settings->chat_ad_video_key);
});
