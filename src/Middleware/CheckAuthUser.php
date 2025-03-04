<?php

namespace Modules\BitesMiddleware\Middleware;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class CheckAuthUser
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $bearerToken = ($request->server('HTTP_AUTHORIZATION'));

        if (str_contains($request->server('REQUEST_URI'), '/login')) {
            $user = $this->loginUsingEmailAndPassword($request->input('email'), $request->input('password'));
            if($user) {
                Auth::login($user, true);
            }
            return $next($request);
        }

        if (empty($bearerToken)) {
            $bearerToken = $request->token;
        }

        if (!empty($bearerToken)) {
            $user = $this->getUserInfoFromBase($bearerToken);
            if (!empty($user->id)) {
                Auth::login($user, true);
            }
        }

        return $next($request);
    }

    private function getUserInfoFromBase($token)
    {
        /** @var Response $response */
        $response = Http::withToken($token)->acceptJson()->get(
            'https://' . env('MIDDLEWARE_SERVER','middleware.bites.com') . '/api/v1/auth/me'
        );
        $responseUser = json_decode($response->getBody()->getContents());
        if(!isset($responseUser->email))
        {
            return null;
        }
        $user = User::where('email', $responseUser->email)->first();
        if(empty($user->id)){
           $user = $this->customRegister($responseUser->name,$responseUser->email);
        }
        return $user;
    }

    private function loginUsingEmailAndPassword($email, $password): User|null
    {
        /** @var Response $response */
        $response = Http::acceptJson()->post(
            'https://' . env('MIDDLEWARE_SERVER','middleware.bites.com') . '/api/v1/auth/login',
            [
                'email' => $email,
                'password' => $password
            ]
        );
        $user = json_decode($response->getBody()->getContents());
        if(!isset($user->access_token))
        {
            return null;
        }
        $user = $this->getUserInfoFromBase($user->access_token);

        return User::where('email', $user->email)->first();
    }

    private function customRegister($name,$email)
    {
        $user = User::create([
                                 'user_role' => 'general',
                                 'username' => rand(100000, 999999),
                                 'name' => $name,
                                 'email' => $email,
                                 'friends' => json_encode(array()),
                                 'followers' => json_encode(array()),
                                 'timezone' => null,
                                 'status' => 0,
                                 'lastActive' => Carbon::now(),
                                 'created_at' => time()
                             ]);

        event(new Registered($user));

        return $user->refresh();
    }
}
