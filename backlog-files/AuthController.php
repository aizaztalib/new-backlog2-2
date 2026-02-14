<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{

    public function redirectToGitHub()
    {
        // Clear any existing session data to prevent state issues
        session()->forget(['github_state', 'url.intended']);

        Log::info('GitHub OAuth: Starting redirect to GitHub', [
            'redirect_uri_config' => config('services.github.redirect'),
            'app_url' => config('app.url'),
            'callback_url' => url('/auth/github/callback'),
        ]);

        return Socialite::driver('github')
            ->scopes(['user:email'])
            ->redirect();
    }


    public function handleGitHubCallback(Request $request)
    {

       Log::info('GitHub OAuth: Callback received', [
            'has_code' => $request->has('code'),
            'has_error' => $request->has('error'),
            'error' => $request->get('error'),
            'error_description' => $request->get('error_description'),
            'state' => $request->get('state'),
            'full_url' => $request->fullUrl(),
        ]);

        try {
            if ($request->has('error')) {
                Log::warning('GitHub OAuth cancelled or denied', [
                    'error' => $request->get('error'),
                    'error_description' => $request->get('error_description'),
                ]);
                   
                return redirect('/login')->withErrors(['error' => 'GitHub authorization was cancelled.']);
            }

            Log::info('GitHub OAuth: Fetching user from Socialite (stateless)');
            $githubUser = Socialite::driver('github')->stateless()->user();
            Log::info('GitHub OAuth: Socialite user fetched successfully');

            if (!$githubUser || !$githubUser->id) {
                Log::error('GitHub OAuth: Invalid user data received', [
                    'githubUser' => $githubUser ? 'object' : 'null',
                ]);
                dd("===================================");
                return redirect('/login')->withErrors(['error' => 'Invalid GitHub user data']);
            }

            $githubId = $githubUser->id;
            $githubEmail = $githubUser->email;
            $githubName = $githubUser->name ?? $githubUser->nickname ?? 'GitHub User';
            $githubUsername = $githubUser->nickname ?? '';
            $githubToken = $githubUser->token ?? '';
            $githubAvatar = $githubUser->avatar ?? '';

            Log::info('GitHub OAuth: User data parsed', [
                'id' => $githubId,
                'email' => $githubEmail ?: '(null)',
                'name' => $githubName,
                'username' => $githubUsername,
            ]);

            if (!$githubEmail) {
dd("mmmmmmmmmm");                
Log::warning('GitHub OAuth: No public email - redirecting to login');
                return redirect('/login')->withErrors(['error' => 'GitHub account must have a public email address. Please make your email public in GitHub settings.']);
            }

            Log::info('GitHub OAuth: Looking up user by github_id and email');
            $user = User::where('github_id', $githubId)
                ->where('email', $githubEmail)
                ->first();

            if ($user) {
                Log::info('GitHub OAuth: Found existing user', ['user_id' => $user->id]);
                $user->update([
                    'github_id' => $githubId,
                    'github_username' => $githubUsername,
                    'github_token' => $githubToken,
                    'avatar' => $githubAvatar
                ]);
                Log::info('GitHub OAuth: Updated user with GitHub info');
            } else {
                Log::info('GitHub OAuth: No matching user - attempting to create new user');
                try {
                    $user = User::create([
                        'first_name' => explode(' ', $githubName)[0] ?? $githubName,
                        'last_name' => explode(' ', $githubName, 2)[1] ?? '',
                        'email' => $githubEmail,
                        'github_id' => $githubId,
                        'github_username' => $githubUsername,
                        'github_token' => $githubToken,
                        'avatar' => $githubAvatar,
                        'password' => Hash::make(Str::random(16)),
                        'email_verified_at' => now(),
                        'role' => '2',
                        'status' => '1'
                    ]);
                    Log::info('GitHub OAuth: New user created', ['user_id' => $user->id]);
                } catch (\Illuminate\Database\QueryException $e) {
                    Log::error('GitHub OAuth: User create failed (likely duplicate email)', [
                        'email' => $githubEmail,
                        'error' => $e->getMessage(),
                        'sql_state' => $e->errorInfo[0] ?? null,
                        'driver_code' => $e->errorInfo[1] ?? null,
                    ]);
                    throw $e;
                }
            }

            Log::info('GitHub OAuth: Calling Auth::login', ['user_id' => $user->id]);
            Auth::login($user);
            Log::info('GitHub OAuth: Auth::login successful, redirecting', [
                'role' => $user->role,
                'intended' => session()->get('url.intended'),
            ]);

            if ($user->role == '0' || $user->role == '1') {
                return redirect()->intended(route('admin.dashboard'));
            } else {
                return redirect()->intended(route('user.dashboard'));
            }
        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::error('GitHub OAuth: InvalidStateException', [
                'message' => $e->getMessage(),
                'request_state' => $request->get('state'),
                'session_state' => session()->get('state'),
                'trace' => $e->getTraceAsString(),
            ]);
            session()->flush();
            return redirect('/login')->withErrors(['error' => 'OAuth session expired. Please try signing in again.']);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('GitHub OAuth: Database/QueryException', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'error_info' => $e->errorInfo ?? null,
            ]);
            dd("bbbbb");
            return redirect('/login')->withErrors(['error' => 'GitHub authentication failed (database error). Please try again or contact support.']);
        } catch (\Exception $e) {
            Log::error('GitHub OAuth: Exception', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            dd("ccccccccc");
            return redirect('/login')->withErrors(['error' => 'GitHub authentication failed. Please try again.']);
        }
    }



    public function linkGitHubAccount()
    {
        if (!Auth::check()) {
            return redirect('/login')->withErrors(['error' => 'Please login first']);
        }

        return Socialite::driver('github')
            ->scopes(['user:email', 'repo'])
            ->redirect();
    }


    public function handleGitHubLinking()
    {
        try {
            if (!Auth::check()) {
                return redirect('/login')->withErrors(['error' => 'Please login first']);
            }

            $githubUser = Socialite::driver('github')->user();

            if (!$githubUser || !$githubUser->id) {
                return redirect()->back()->withErrors(['error' => 'Invalid GitHub user data']);
            }

            $user = Auth::user();

            // Check if GitHub account is already linked to another user
            $existingUser = User::where('github_id', $githubUser->id)
                ->where('id', '!=', $user->id)
                ->first();

            if ($existingUser) {
                return redirect()->back()->withErrors(['error' => 'This GitHub account is already linked to another user']);
            }

            $user->update([
                'github_id' => $githubUser->id,
                'github_username' => $githubUser->nickname ?? '',
                'github_token' => $githubUser->token ?? '',
                'avatar' => $githubUser->avatar ?? $user->avatar
            ]);

            return redirect()->back()->with('success', 'GitHub account linked successfully!');
        } catch (\Exception $e) {
            Log::error('GitHub Linking Error: ' . $e->getMessage());
            return redirect()->back()->withErrors(['error' => 'Failed to link GitHub account: ' . $e->getMessage()]);
        }
    }
}
