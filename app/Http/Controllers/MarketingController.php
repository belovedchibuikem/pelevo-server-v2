<?php

namespace App\Http\Controllers;

use App\Mail\PelevoNotice;
use App\Services\MailPreference;
use App\Support\FoundingCreators;
use App\Support\MarketingLegal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
                'updated' => '30th September 2026',
                'kicker' => 'Last updated: 30th September 2026',
                'intro' => '',
                'sections' => MarketingLegal::privacy(),
            ],
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('Public/Legal', [
            ...$this->shared(),
            'document' => [
                'title' => 'Terms and Conditions of Use',
                'updated' => '30th September 2026',
                'kicker' => 'Last updated: 30th September 2026',
                'intro' => [
                    'These Terms and Conditions ("Terms") govern your access to and use of the Pelevo mobile application and any related services (together, the "Service"), provided by Pod Emeralds Limited ("Pelevo," "we," "us," or "our"), a company incorporated under the laws of the Federal Republic of Nigeria, registered address Plot 109, Girls Mall Estate, Coal City Garden, Enugu State, Nigeria.',
                    'By creating an account, downloading the app, or otherwise using the Service, you agree to be bound by these Terms and by our [Privacy Policy](/privacy) (available in the app and at pelevo.com), which is incorporated by reference. If you do not agree, do not use the Service.',
                ],
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
        Cache::forget('admin:module-counts:support');
        $mail = app(MailPreference::class);
        $mail->queueTransactional($data['email'], new PelevoNotice(
            subjectLine: 'We received your message to Pelevo',
            eyebrow: 'Contact',
            heading: 'Thanks, we have your note',
            intro: 'Hi '.$data['name'].', the Pelevo team received your message and will reply to this email.',
            detail: $data['subject']."\n\n".$data['message'],
        ));
        $mail->queueTransactional(config('mail.from.address'), new PelevoNotice(
            subjectLine: 'Website contact: '.$data['subject'],
            eyebrow: 'Operations',
            heading: 'New website contact',
            intro: $data['name'].' ('.$data['email'].', '.$data['audience'].') sent a message from pelevo.com.',
            detail: $data['message'],
        ));

        return back()->with('status', 'Thank you. A member of the Pelevo team will reply to the email you provided.');
    }

    public function foundingCreators(): Response
    {
        return Inertia::render('Public/FoundingCreators', [
            ...$this->shared(),
            'frequencies' => FoundingCreators::options(FoundingCreators::FREQUENCIES),
        ]);
    }

    public function submitFoundingCreator(Request $request): RedirectResponse
    {
        $success = 'You are on the list. We will email your claim link closer to launch.';
        if (filled($request->input('company_website'))) {
            return back()->with('status', $success);
        }

        $showUrl = trim((string) $request->input('show_url'));
        if ($showUrl !== '' && ! preg_match('#^https?://#i', $showUrl)) {
            $request->merge(['show_url' => 'https://'.$showUrl]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'show_name' => ['required', 'string', 'max:191'],
            'show_url' => ['required', 'url:http,https', 'max:500'],
            'email' => ['required', 'email', 'max:191'],
            'social_handle' => ['nullable', 'string', 'max:120'],
            'publish_frequency' => ['nullable', Rule::in(array_keys(FoundingCreators::FREQUENCIES))],
            'notes' => ['nullable', 'string', 'max:3000'],
        ], [
            'show_url.url' => 'Enter a link to your show, for example a Spotify, Apple Podcasts or hosting page.',
        ], [
            'show_name' => 'podcast/show name',
            'show_url' => 'show link',
        ]);

        $email = Str::lower($data['email']);
        $values = [
            'name' => $data['name'],
            'show_name' => $data['show_name'],
            'show_url' => $data['show_url'],
            'email' => $email,
            'social_handle' => $data['social_handle'] ?? null,
            'publish_frequency' => $data['publish_frequency'] ?? null,
            'notes' => $data['notes'] ?? null,
            'updated_at' => now(),
        ];
        $existing = DB::table('founding_creator_applications')
            ->where('email', $email)
            ->whereRaw('LOWER(show_name) = ?', [Str::lower($data['show_name'])])
            ->first();

        if ($existing) {
            DB::table('founding_creator_applications')->where('id', $existing->id)->update([
                ...$values,
                'submission_count' => $existing->submission_count + 1,
            ]);

            return back()->with('status', $success);
        }

        DB::table('founding_creator_applications')->insert([
            ...$values,
            'id' => (string) Str::ulid(),
            'state' => 'new',
            'created_at' => now(),
        ]);

        $mail = app(MailPreference::class);
        $mail->queueTransactional($email, new PelevoNotice(
            subjectLine: 'Your Pelevo founding creator spot is reserved',
            eyebrow: 'Founding creators',
            heading: 'You are on the list',
            intro: 'Hi '.$data['name'].', thanks for reserving a founding creator spot for '.$data['show_name'].'. We will email your claim link to this address closer to launch.',
            detail: 'No action is needed right now. Reply to this email if anything about your show changes.',
        ));
        $mail->queueTransactional(config('mail.from.address'), new PelevoNotice(
            subjectLine: 'Founding creator: '.$data['show_name'],
            eyebrow: 'Operations',
            heading: 'New founding creator sign-up',
            intro: $data['name'].' ('.$email.') reserved a spot for '.$data['show_name'].'.',
            detail: $data['show_url'],
        ));

        return back()->with('status', $success);
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
