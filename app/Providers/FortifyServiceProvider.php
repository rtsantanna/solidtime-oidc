<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Enums\RegistrationMode;
use App\Extensions\Fortify\CustomLoginResponse;
use App\Extensions\Fortify\CustomLogoutResponse;
use App\Extensions\Fortify\CustomTwoFactorLoginResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::registerView(function () {
            return Inertia::render('Auth/Register', [
                'email' => session('registration_email', ''),
                'terms_url' => config('auth.terms_url'),
                'privacy_policy_url' => config('auth.privacy_policy_url'),
                'newsletter_consent' => config('auth.newsletter_consent'),
            ]);
        });

        Fortify::loginView(function (Request $request) {
            $oidcEnabled = (bool) config('services.oidc.enabled')
                && filled(config('services.oidc.issuer'))
                && filled(config('services.oidc.client_id'));

            return Inertia::render('Auth/Login', [
                'canResetPassword' => Route::has('password.request'),
                // Upstream usa o enum RegistrationMode; o fork fazia (bool) sobre a string,
                // e (bool) 'off' e TRUE em PHP - mostraria o link de registro com registro desligado.
                'canRegister' => RegistrationMode::fromConfig(config('app.enable_registration')) !== RegistrationMode::Off
                    && Route::has('register'),
                'passwordLoginEnabled' => (bool) config('auth.password_login_enabled', true),
                'status' => $request->session()->get('status'),
                'oidc' => [
                    'enabled' => $oidcEnabled,
                    'label' => (string) config('services.oidc.button_label'),
                    'url' => $oidcEnabled ? route('oidc.redirect') : null,
                ],
            ]);
        });

        Fortify::requestPasswordResetLinkView(function () {
            return Inertia::render('Auth/ForgotPassword', [
                'status' => session('status'),
            ]);
        });

        Fortify::resetPasswordView(function (Request $request) {
            return Inertia::render('Auth/ResetPassword', [
                'email' => $request->input('email'),
                'token' => $request->route('token'),
            ]);
        });

        Fortify::verifyEmailView(function () {
            return Inertia::render('Auth/VerifyEmail', [
                'status' => session('status'),
            ]);
        });

        Fortify::twoFactorChallengeView(function () {
            return Inertia::render('Auth/TwoFactorChallenge');
        });

        Fortify::confirmPasswordView(function () {
            return Inertia::render('Auth/ConfirmPassword');
        });

        Fortify::authenticateUsing(function (Request $request): ?User {
            if (! (bool) config('auth.password_login_enabled', true)) {
                throw ValidationException::withMessages([
                    Fortify::username() => [__('Password login is disabled.')],
                ]);
            }

            /** @var User|null $user */
            $user = User::query()
                ->where('email', $request->email)
                ->where('is_placeholder', '=', false)
                ->first();

            if ($user !== null && Hash::check($request->password, $user->password)) {
                return $user;
            }

            return null;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        $this->app->instance(LoginResponseContract::class, new CustomLoginResponse);
        $this->app->bind(LogoutResponse::class, CustomLogoutResponse::class);
        $this->app->instance(TwoFactorLoginResponse::class, new CustomTwoFactorLoginResponse);
    }
}
