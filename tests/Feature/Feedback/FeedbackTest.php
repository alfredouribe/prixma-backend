<?php

use App\Mail\NewFeedbackMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createFeedbackUser(): array
{
    $user = User::factory()->withCompletedOnboarding()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    return compact('user', 'token');
}

describe('POST /api/feedback', function () {
    it('envía un reporte de bug con mensaje válido', function () {
        Mail::fake();

        ['user' => $user, 'token' => $token] = createFeedbackUser();

        $response = $this->withToken($token)->postJson('/api/feedback', [
            'type' => 'bug',
            'message' => 'La app se cierra al abrir el chat.',
            'app_version' => '1.2.3',
            'platform' => 'android',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => null,
                'message' => 'Gracias, recibimos tu mensaje.',
            ]);

        $this->assertDatabaseHas('feedback', [
            'user_id' => $user->id,
            'type' => 'bug',
            'message' => 'La app se cierra al abrir el chat.',
            'app_version' => '1.2.3',
            'platform' => 'android',
        ]);

        Mail::assertQueued(NewFeedbackMail::class, function ($mail) use ($user) {
            return $mail->hasTo(config('services.feedback.notify_email'))
                && (string) $mail->feedback->user_id === (string) $user->id;
        });
    });

    it('envía un comentario libre válido', function () {
        Mail::fake();

        ['token' => $token] = createFeedbackUser();

        $response = $this->withToken($token)->postJson('/api/feedback', [
            'type' => 'comment',
            'message' => 'Me encantaría poder filtrar por intereses.',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('feedback', [
            'type' => 'comment',
            'message' => 'Me encantaría poder filtrar por intereses.',
            'app_version' => null,
            'platform' => null,
        ]);
    });

    it('falta message retorna 422', function () {
        ['token' => $token] = createFeedbackUser();

        $this->withToken($token)->postJson('/api/feedback', [
            'type' => 'bug',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    });

    it('type inválido retorna 422', function () {
        ['token' => $token] = createFeedbackUser();

        $this->withToken($token)->postJson('/api/feedback', [
            'type' => 'not-a-real-type',
            'message' => 'Hola',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    });

    it('sin autenticación retorna 401', function () {
        $this->postJson('/api/feedback', [
            'type' => 'bug',
            'message' => 'Hola',
        ])->assertStatus(401);
    });

    it('un fallo al encolar el correo no revierte el registro ya creado', function () {
        ['user' => $user, 'token' => $token] = createFeedbackUser();

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('Fallo simulado al encolar el correo.'));

        $response = $this->withToken($token)->postJson('/api/feedback', [
            'type' => 'comment',
            'message' => 'Esto debe guardarse aunque el correo falle.',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('feedback', [
            'user_id' => $user->id,
            'message' => 'Esto debe guardarse aunque el correo falle.',
        ]);
    });
});
