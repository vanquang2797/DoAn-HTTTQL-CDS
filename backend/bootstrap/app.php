<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['active' => ActiveAccount::class]);
        $middleware->appendToGroup('api', SetApiLocale::class);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = match (true) {
                $e instanceof ValidationException => 422,
                $e instanceof AuthenticationException => 401,
                $e instanceof AuthorizationException => 403,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                $e instanceof UniqueConstraintViolationException => 409,
                default => 500,
            };
            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];
            if ($e instanceof ValidationException) {
                $messages = $e->validator->errors()->all();
                $message = array_shift($messages) ?? 'Dữ liệu gửi lên không hợp lệ.';
                if ($messages !== []) {
                    $message .= ' (còn '.count($messages).' lỗi khác.)';
                }
            } else {
                $message = $status >= 500
                    ? 'Không thể hoàn tất yêu cầu.'
                    : ($status === 409 && $e instanceof QueryException ? 'Dữ liệu bị trùng hoặc đã thay đổi.' : $e->getMessage());
            }

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $e instanceof ValidationException ? $e->errors() : (object) [],
            ], $status, $headers);
        });
    })->create();
