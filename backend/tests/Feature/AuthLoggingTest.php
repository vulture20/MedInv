<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Login activity audit trail (AuthController): a successful login was
 * previously never logged at all, only failures — and even those only
 * carried the client IP and error_code, not the attempted email, making it
 * hard to tell *who* a given failed-login/account-locked entry was even
 * about.
 *
 * Log::shouldReceive('debug')->zeroOrMoreTimes() is present in every test
 * here alongside the specific expectation under test: LogFrontendAccess
 * logs every single request at DEBUG (including /api/login itself), and
 * once any Log:: method has an explicit expectation the facade is fully
 * swapped for a fake — an unrelated, unmocked method call on it throws
 * "no expectations were specified", which cascades into a confusing
 * failure once Laravel's own exception reporting then also tries (and
 * fails) to Log::error() *that*.
 *
 * GitHub issue #42: this whole file was flaky — roughly 1 in 30 full-suite
 * runs, never reproduced running this class in isolation — with exactly
 * the "no expectations were specified" failure above on Log::error(),
 * meaning something genuinely (if rarely) throws an uncaught exception
 * during one of these requests. No app code calls Log::error() directly
 * anywhere in this codebase, so that call can only be Laravel's own
 * default exception handler reporting whatever really went wrong — but
 * Mockery's bare rejection discards the exception itself, so the one
 * historical failure gave no clue what it actually was. >75 additional
 * full-suite runs during this investigation still didn't reproduce it, so
 * the underlying trigger remains unidentified. $unexpectedLoggedError/
 * setUp()/tearDown() below don't fix that (an unknown bug can't be
 * "fixed" without knowing what it is) but close the actual reported pain
 * point: the *next* time this recurs, in this file or on this exact CI
 * run or a year from now, the test failure will carry the real
 * exception's class, message, and stack trace instead of Mockery's opaque
 * rejection — no more repeating this investigation from scratch.
 *
 * The capture-then-fail-in-tearDown() split (rather than failing directly
 * from inside the Log::error() mock) is deliberate, not stylistic: that
 * mock runs *while Laravel's own exception handler is still on the call
 * stack* (Kernel::handle()'s catch block, mid-`report()`). Throwing
 * straight out of there — verified live, it reliably hung the whole test
 * process rather than failing it — re-enters the same exception-reporting
 * machinery whose already-broken state is what got us here in the first
 * place, instead of surfacing as a normal PHPUnit failure. Recording the
 * exception and failing afterwards, once control is back in a normal
 * PHPUnit lifecycle method with nothing Laravel-internal still unwinding,
 * avoids that risk entirely.
 */
class AuthLoggingTest extends TestCase
{
    use RefreshDatabase;

    private ?\Throwable $unexpectedLoggedError = null;

    protected function setUp(): void
    {
        parent::setUp();

        Log::shouldReceive('error')->zeroOrMoreTimes()->andReturnUsing(function (string $message, array $context = []) {
            $this->unexpectedLoggedError = $context['exception'] ?? new \RuntimeException($message);
        });
    }

    protected function tearDown(): void
    {
        $captured = $this->unexpectedLoggedError;
        $this->unexpectedLoggedError = null;

        // parent::tearDown() must always run (it rolls back RefreshDatabase's
        // transaction and flushes the container) even when $captured is set —
        // but it also runs Mockery::close(), which throws its own, far less
        // useful "expected once, called 0 times" error the moment the request
        // that triggered Log::error() never reached this test's own Log::info()
        // expectation (exactly what happens once something throws before
        // AuthController::login() gets there). try/finally, not a plain
        // sequential call, so that unmet-expectation error doesn't pre-empt —
        // and prevent us from ever reaching — the actually useful failure
        // below; finally still lets a genuinely unrelated tearDown() failure
        // propagate normally when $captured is null.
        try {
            parent::tearDown();
        } finally {
            if ($captured) {
                self::fail(sprintf(
                    "Log::error() was called during the request (GitHub issue #42) — %s: %s in %s:%d\n%s",
                    $captured::class,
                    $captured->getMessage(),
                    $captured->getFile(),
                    $captured->getLine(),
                    $captured->getTraceAsString(),
                ));
            }
        }
    }

    public function test_a_successful_login_is_logged_with_user_id_email_and_ip(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true, 'password' => 'correct-password']);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->once()->with('User logged in', Mockery::on(function ($context) use ($user) {
            return $context['user_id'] === $user->id
                && $context['email'] === $user->email
                && array_key_exists('ip', $context);
        }));

        // A stateful-matching Origin header is required for the success path,
        // which calls $request->session()->regenerate() — without it Sanctum's
        // EnsureFrontendRequestsAreStateful treats the request as a plain
        // stateless API call (no session middleware at all), and that call
        // throws "Session store not set on request." No existing test in this
        // suite exercised a *successful* login via the real HTTP endpoint
        // before this, so this gap had gone unnoticed.
        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password'], ['Origin' => 'http://localhost:5173']);

        $response->assertOk();
    }

    public function test_a_failed_login_is_logged_with_the_attempted_email(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => true, 'password' => 'correct-password']);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'Invalid credentials.', Mockery::on(function ($context) use ($user) {
            return $context['error_code'] === 'invalid_credentials'
                && $context['email'] === $user->email
                && array_key_exists('ip', $context);
        }));

        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $response->assertStatus(422);
    }

    public function test_a_deactivated_account_login_attempt_is_logged_with_its_email(): void
    {
        $user = User::factory()->create(['level' => 'user', 'is_active' => false, 'password' => 'correct-password']);

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'This account has been deactivated.', Mockery::on(function ($context) use ($user) {
            return $context['error_code'] === 'account_deactivated' && $context['email'] === $user->email;
        }));

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password']);
    }

    public function test_logging_in_as_a_nonexistent_email_still_logs_the_attempted_address(): void
    {
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('log')->once()->with('warning', 'Invalid credentials.', Mockery::on(function ($context) {
            return $context['error_code'] === 'invalid_credentials' && $context['email'] === 'nobody@example.com';
        }));

        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'whatever']);
    }
}
