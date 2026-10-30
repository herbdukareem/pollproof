<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ussd\UssdMenu;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Africa's Talking USSD callback. AT posts sessionId, serviceCode, phoneNumber, networkCode and text
 * (every answer so far, joined with "*") and shows our plain-text reply: "CON ..." keeps the session
 * open for another answer, "END ..." closes it.
 *
 * AT does not sign these requests, so the callback URL carries a long secret (POLLPROOF_USSD_TOKEN).
 * The agent is the registered phone number the network reports, the same trust as an SMS result;
 * every USSD result still goes through second-person verification and is marked as unsigned.
 */
class UssdController extends Controller
{
    public function __invoke(Request $request, string $token, UssdMenu $menu): Response
    {
        $expected = (string) config('pollproof.ussd.token');
        abort_unless($expected !== '' && hash_equals($expected, $token), 404);

        $sessionId = (string) $request->input('sessionId', '');
        $phone = (string) $request->input('phoneNumber', '');
        $text = trim((string) $request->input('text', ''));
        if ($sessionId === '' || $phone === '' || strlen($text) > 2000) {
            return $this->reply('END Invalid request.');
        }

        // The same answers in the same session always get the same reply; this also makes a
        // retried request from the network harmless after a result has been sent.
        $reply = Cache::get($key = 'ussd:reply:'.sha1($sessionId.'|'.$text));
        if ($reply === null) {
            $reply = $this->answer($menu, $sessionId, $phone, $text, (string) $request->ip());
            Cache::put($key, $reply, now()->addMinutes(10));
        }

        return $this->reply($reply);
    }

    private function answer(UssdMenu $menu, string $sessionId, string $phone, string $text, string $ip): string
    {
        $local = Phone::normalize($phone);
        $agent = $local ? User::where('phone', $local)->where('role', Role::Agent)->first() : null;
        if (! $agent) {
            return 'END This number is not registered with PollProof. Ask your supervisor to add you.';
        }
        if (! $agent->isActive()) {
            return 'END This number is no longer active on PollProof. Call your supervisor.';
        }
        if ($agent->campaign->isDeclared()) {
            return 'END The result has been declared. PollProof is no longer taking results.';
        }
        if (! $agent->polling_unit_id) {
            return 'END You are a reserve agent with no polling unit yet. Your supervisor will send you to one.';
        }

        return $menu->handle($agent, $sessionId, $text, $ip);
    }

    private function reply(string $text): Response
    {
        return response($text, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
