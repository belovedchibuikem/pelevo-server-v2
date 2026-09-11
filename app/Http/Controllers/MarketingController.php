<?php

namespace App\Http\Controllers;

use App\Support\MarketingLegal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class MarketingController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('Public/Home', $this->shared());
    }

    public function howItWorks(): Response
    {
        return Inertia::render('Public/HowItWorks', $this->shared());
    }

    public function privacy(): Response
    {
        return Inertia::render('Public/Legal', [
            ...$this->shared(),
            'document' => [
                'title' => 'Privacy Policy',
                'updated' => '9 September 2026',
                'intro' => 'How Pelevo collects, uses, and protects listener, creator, and financial data.',
                'sections' => MarketingLegal::privacy(),
            ],
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('Public/Legal', [
            ...$this->shared(),
            'document' => [
                'title' => 'Terms of Use',
                'updated' => '9 September 2026',
                'intro' => 'The contract for using Pelevo apps, Creator Studio, Earn, gifts, and Premium.',
                'sections' => MarketingLegal::terms(),
            ],
        ]);
    }

    public function contact(): Response
    {
        return Inertia::render('Public/Contact', $this->shared());
    }

    public function submitContact(Request $request): RedirectResponse
    {
        if (filled($request->input('company_website'))) {
            return back()->with('status', 'Thank you. Your message has been received.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191'],
            'audience' => ['required', 'in:listener,creator,press,privacy,other'],
            'subject' => ['required', 'string', 'max:191'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        DB::table('contact_inquiries')->insert([
            'id' => (string) Str::ulid(),
            'name' => $data['name'],
            'email' => $data['email'],
            'audience' => $data['audience'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'state' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Thank you. A member of the Pelevo team will reply to the email you provided.');
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        return [
            'stores' => [
                'ios' => config('app.ios_store_url'),
                'android' => config('app.android_store_url'),
            ],
        ];
    }
}
