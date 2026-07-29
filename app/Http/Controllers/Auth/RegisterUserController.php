<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterUserRequest;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterUserController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));
        Auth::login($user);

        return redirect('/');
    }

    public function apiStore(RegisterUserRequest $request)
    {
        $validated = $request->validated();

        [$user, $hotel] = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => UserRole::ADMIN,
            ]);

            $hotel = Hotel::create([
                'owner_id' => $user->id,
                'name' => $validated['hotel']['name'],
                'slug' => Str::slug($validated['hotel']['name']),
                'city' => $validated['hotel']['city'],
                'country_code' => $validated['hotel']['country_code'] ?? null,
                'address' => $validated['hotel']['address'] ?? null,
                'timezone' => $validated['hotel']['timezone'] ?? 'UTC',
                'currency' => $validated['hotel']['currency'] ?? 'USD',
                'email' => $validated['hotel']['email'] ?? null,
                'phone' => $validated['hotel']['phone'] ?? null,
                'whatsapp_number' => $validated['hotel']['whatsapp_number'] ?? null,
            ]);

            return [$user, $hotel];
        });

        event(new Registered($user));

        return apiResponse('User registered successfully.', 201, [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'hotel' => [
                'id' => $hotel->id,
                'name' => $hotel->name,
                'slug' => $hotel->slug,
            ],
        ]);
    }
}
