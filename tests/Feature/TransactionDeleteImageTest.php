<?php

use App\Models\Campus;
use App\Models\Period;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

test('can delete transaction image', function () {
    Storage::fake('public');

    $user = User::factory()->create(['role' => 'super_admin']);
    Sanctum::actingAs($user);

    $campus = Campus::create([
        'name' => 'Campus Test',
        'code' => 'CAMPUS_TEST',
        'description' => null,
        'address' => null,
        'is_active' => true,
    ]);

    $period = Period::create([
        'name' => 'Periodo Test',
        'price' => 1000,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
    ]);

    $student = Student::create([
        'period_id' => $period->id,
        'username' => 'student_test',
        'email' => 'student_test@example.com',
        'campus_id' => $campus->id,
    ]);

    $imagePath = 'transactions/test.jpg';
    Storage::disk('public')->put($imagePath, 'fake-image');

    $transaction = Transaction::create([
        'student_id' => $student->id,
        'campus_id' => $campus->id,
        'amount' => 100,
        'paid' => true,
        'payment_method' => 'cash',
        'transaction_type' => 'income',
        'payment_date' => now(),
        'expiration_date' => now()->addDays(15)->toDateString(),
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'image' => $imagePath,
    ]);

    $response = $this->deleteJson("/api/charges/{$transaction->id}/image");

    $response->assertStatus(200);

    expect(Storage::disk('public')->exists($imagePath))->toBeFalse();
    expect($transaction->refresh()->image)->toBeNull();
});

test('cannot delete transaction image without permission', function () {
    Storage::fake('public');

    $user = User::factory()->create(['role' => 'teacher']);
    Sanctum::actingAs($user);

    $campus = Campus::create([
        'name' => 'Campus Test',
        'code' => 'CAMPUS_TEST',
        'description' => null,
        'address' => null,
        'is_active' => true,
    ]);

    $period = Period::create([
        'name' => 'Periodo Test',
        'price' => 1000,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
    ]);

    $student = Student::create([
        'period_id' => $period->id,
        'username' => 'student_test',
        'email' => 'student_test@example.com',
        'campus_id' => $campus->id,
    ]);

    $imagePath = 'transactions/test.jpg';
    Storage::disk('public')->put($imagePath, 'fake-image');

    $transaction = Transaction::create([
        'student_id' => $student->id,
        'campus_id' => $campus->id,
        'amount' => 100,
        'paid' => true,
        'payment_method' => 'cash',
        'transaction_type' => 'income',
        'payment_date' => now(),
        'expiration_date' => now()->addDays(15)->toDateString(),
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'image' => $imagePath,
    ]);

    $response = $this->deleteJson("/api/charges/{$transaction->id}/image");

    $response->assertStatus(403);

    expect(Storage::disk('public')->exists($imagePath))->toBeTrue();
    expect($transaction->refresh()->image)->toBe($imagePath);
});
