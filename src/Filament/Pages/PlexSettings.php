<?php

namespace EmilMoe\PlexRecommender\Filament\Pages;

use EmilMoe\PlexRecommender\Auth\PlexTv;
use EmilMoe\PlexRecommender\Claude\AnthropicApiDriver;
use EmilMoe\PlexRecommender\Claude\ClaudeAdvisor;
use EmilMoe\PlexRecommender\Claude\ClaudeCodeDriver;
use EmilMoe\PlexRecommender\Claude\ClaudeException;
use EmilMoe\PlexRecommender\Filament\Pages\Concerns\UsesPluginNavigation;
use EmilMoe\PlexRecommender\Filament\PlexRecommenderPlugin;
use EmilMoe\PlexRecommender\RecommenderService;
use EmilMoe\PlexRecommender\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Throwable;

class PlexSettings extends Page
{
    use UsesPluginNavigation;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Indstillinger';

    protected static ?string $title = 'Plex-indstillinger';

    protected static ?string $slug = 'plex/indstillinger';

    protected static ?int $navigationSort = 30;

    protected string $view = 'plex-recommender::filament.settings';

    public ?array $data = [];

    /** Pending plex.tv PIN while the login popup is open. */
    #[Locked]
    public ?array $pin = null;

    /** Servers to choose from after login: id => name. */
    #[Locked]
    public array $serverOptions = [];

    public static function canAccess(): bool
    {
        try {
            return PlexRecommenderPlugin::get()->userCanManageSettings();
        } catch (Throwable) {
            return true;
        }
    }

    protected function settings(): Settings
    {
        return app(Settings::class);
    }

    public function mount(): void
    {
        $s = $this->settings();
        $this->form->fill([
            'server_url' => $s->url(),
            'server_token' => $s->token(),
            'tmdb_api_key' => $s->tmdbApiKey(),
            'language' => $s->language(),
            'min_votes' => $s->minVotes(),
            'account_id' => $s->accountId(),
            'verify_ssl' => $s->verifySsl(),
            'cache_minutes' => (int) round($s->cacheTtl() / 60),
            'recency_half_life_days' => $s->halfLifeDays(),
            'claude_driver' => $s->claudeDriver(),
            'claude_binary' => $s->claudeBinary(),
            'claude_model' => $s->claudeModel(),
            'anthropic_api_key' => $s->anthropicApiKey(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Plex')
                ->description('Forbind din Plex-konto. Så findes din server automatisk.')
                ->icon(Heroicon::OutlinedPlay)
                ->schema([
                    Callout::make(fn () => $this->connectionHeading())
                        ->description(fn () => $this->connectionDescription())
                        ->status(fn () => match (true) {
                            $this->pin !== null => 'info',
                            $this->settings()->connected() => 'success',
                            default => 'warning',
                        }),
                    Actions::make([
                        $this->loginAction(),
                        $this->testConnectionAction(),
                        $this->disconnectAction(),
                    ])->key('plexActions'),
                    Select::make('server_choice')
                        ->label('Vælg server')
                        ->options(fn () => $this->serverOptions)
                        ->visible(fn () => count($this->serverOptions) > 1)
                        ->live()
                        ->afterStateUpdated(fn (?string $state) => $state && $this->chooseServer($state))
                        ->helperText('Din konto har adgang til flere servere. Vælg den, anbefalingerne skal bygge på.')
                        ->dehydrated(false),
                    Section::make('Manuel opsætning')
                        ->description('Kun nødvendigt hvis login ikke virker, f.eks. uden internetadgang.')
                        ->collapsible()
                        ->collapsed()
                        ->compact()
                        ->schema([
                            TextInput::make('server_url')
                                ->label('Serveradresse')
                                ->placeholder('http://192.168.1.10:32400')
                                ->url(),
                            TextInput::make('server_token')
                                ->label('Plex-token')
                                ->password()
                                ->revealable(),
                        ]),
                ]),

            Section::make('Forslag til nye titler')
                ->description('Bruger The Movie Database (TMDB) til at finde film og serier, du ikke har endnu.')
                ->icon(Heroicon::OutlinedSparkles)
                ->afterHeader([$this->testTmdbAction()])
                ->schema([
                    TextInput::make('tmdb_api_key')
                        ->label('TMDB API-nøgle')
                        ->password()
                        ->revealable()
                        ->helperText(new HtmlString(
                            'Gratis. Opret en konto på <a href="https://www.themoviedb.org/signup" target="_blank" style="text-decoration: underline">themoviedb.org</a>, '
                            .'gå til <a href="https://www.themoviedb.org/settings/api" target="_blank" style="text-decoration: underline">Indstillinger → API</a> '
                            .'og kopiér "API Read Access Token" eller "API Key" herind.'
                        )),
                    Select::make('language')
                        ->label('Sprog for titler og beskrivelser')
                        ->options([
                            'da-DK' => 'Dansk', 'en-US' => 'Engelsk', 'sv-SE' => 'Svensk',
                            'nb-NO' => 'Norsk', 'de-DE' => 'Tysk', 'fr-FR' => 'Fransk', 'es-ES' => 'Spansk',
                        ])
                        ->selectablePlaceholder(false),
                    TextInput::make('min_votes')
                        ->label('Kun populære titler')
                        ->helperText('Mindste antal stemmer på TMDB. Højere tal giver mere kendte titler.')
                        ->numeric()
                        ->minValue(0),
                ])
                ->columns(['md' => 2]),

            Section::make('Spørg Claude')
                ->description('Personlige forslag skrevet af Claude, med begrundelse.')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->afterHeader([$this->testClaudeAction()])
                ->schema([
                    Select::make('claude_driver')
                        ->label('Hvordan skal Claude bruges?')
                        ->options([
                            'code' => 'Claude Code på denne computer (ingen API-nøgle)',
                            'api' => 'Claude API med API-nøgle',
                            'off' => 'Slået fra',
                        ])
                        ->selectablePlaceholder(false)
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('claude_binary')
                        ->label('Sti til Claude Code')
                        ->placeholder('claude')
                        ->helperText('Normalt fundet automatisk. Virker det ikke, så kør `which claude` i en terminal og indsæt stien.')
                        ->visible(fn ($get) => $get('claude_driver') === 'code'),
                    TextInput::make('anthropic_api_key')
                        ->label('Anthropic API-nøgle')
                        ->password()
                        ->revealable()
                        ->helperText(new HtmlString(
                            'Fra <a href="https://console.anthropic.com/settings/keys" target="_blank" style="text-decoration: underline">console.anthropic.com</a>. '
                            .'Betales efter forbrug, separat fra dit Claude-abonnement. Kræver <code>composer require anthropic-ai/sdk</code>.'
                        ))
                        ->visible(fn ($get) => $get('claude_driver') === 'api'),
                    TextInput::make('claude_model')
                        ->label('Model (valgfri)')
                        ->placeholder(fn ($get) => $get('claude_driver') === 'api' ? 'claude-opus-5-5' : 'Claude Codes standard')
                        ->helperText('Lad stå tom for standard.')
                        ->visible(fn ($get) => $get('claude_driver') !== 'off'),
                ])
                ->columns(['md' => 2]),

            Section::make('Avanceret')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->collapsible()
                ->collapsed()
                ->schema([
                    Select::make('account_id')
                        ->label('Hvis historik')
                        ->placeholder('Alle brugere på serveren')
                        ->options(fn () => $this->accountOptions())
                        ->helperText('Deler I serveren, kan anbefalingerne baseres på én persons historik.'),
                    TextInput::make('recency_half_life_days')
                        ->label('Hvor hurtigt gammel historik glemmes (dage)')
                        ->helperText('Efter så mange dage tæller en visning halvt så meget.')
                        ->numeric()
                        ->minValue(1),
                    TextInput::make('cache_minutes')
                        ->label('Opdater biblioteket hvert (minutter)')
                        ->numeric()
                        ->minValue(0),
                    Toggle::make('verify_ssl')
                        ->label('Kontrollér serverens certifikat')
                        ->helperText('Slå kun fra, hvis din server bruger et selvsigneret certifikat.'),
                ])
                ->columns(['md' => 2]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Gem indstillinger')->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $this->settings()->put([
            'server_url' => $data['server_url'] ? rtrim($data['server_url'], '/') : null,
            'server_token' => $data['server_token'] ?: null,
            'tmdb_api_key' => $data['tmdb_api_key'] ? trim($data['tmdb_api_key']) : null,
            'language' => $data['language'] ?: 'da-DK',
            'min_votes' => (int) ($data['min_votes'] ?? 50),
            'account_id' => $data['account_id'] ?: null,
            'verify_ssl' => (bool) $data['verify_ssl'],
            'cache_ttl' => max(0, (int) ($data['cache_minutes'] ?? 10)) * 60,
            'recency_half_life_days' => max(1, (int) ($data['recency_half_life_days'] ?? 180)),
            'claude_driver' => $data['claude_driver'] ?? 'code',
            'claude_binary' => trim((string) ($data['claude_binary'] ?? '')) ?: null,
            'claude_model' => trim((string) ($data['claude_model'] ?? '')) ?: null,
            'anthropic_api_key' => trim((string) ($data['anthropic_api_key'] ?? '')) ?: null,
        ]);
        app(RecommenderService::class)->refresh();

        Notification::make()->success()->title('Indstillingerne er gemt')->send();
    }

    // ---- Plex login (popup + polling, like Overseerr) --------------------

    protected function loginAction(): Action
    {
        return Action::make('plexLogin')
            ->label(fn () => $this->settings()->connected() ? 'Log ind igen / skift server' : 'Log ind med Plex')
            ->icon(Heroicon::ArrowRightEndOnRectangle)
            ->color(fn () => $this->settings()->connected() ? 'gray' : 'warning')
            // Open the popup synchronously on click so browsers don't block it,
            // then let Livewire create the PIN and point the popup at plex.tv.
            ->alpineClickHandler("window.plexAuthPopup = window.open('about:blank', 'plexauth', 'width=800,height=760'); \$wire.startPlexLogin()");
    }

    public function startPlexLogin(): void
    {
        $plexTv = app(PlexTv::class);
        try {
            $pin = $plexTv->createPin();
        } catch (Throwable) {
            $this->js('window.plexAuthPopup && window.plexAuthPopup.close()');
            Notification::make()->danger()->title('Kunne ikke kontakte plex.tv')
                ->body('Tjek at serveren, appen kører på, har adgang til internettet.')->send();

            return;
        }
        $this->pin = $pin + ['started' => time()];
        $this->serverOptions = [];
        $url = json_encode($plexTv->authUrl($pin['code']));
        $this->js("(window.plexAuthPopup && !window.plexAuthPopup.closed) ? (window.plexAuthPopup.location = {$url}) : (window.plexAuthPopup = window.open({$url}, 'plexauth', 'width=800,height=760'))");
    }

    /** Called by wire:poll while a PIN is pending. */
    public function checkPlexLogin(): void
    {
        if (! $this->pin) {
            return;
        }
        if (time() - ($this->pin['started'] ?? time()) > 600) {
            $this->pin = null;
            Notification::make()->warning()->title('Login udløb. Prøv igen.')->send();

            return;
        }

        $plexTv = app(PlexTv::class);
        try {
            $token = $plexTv->checkPin($this->pin['id'], $this->pin['code']);
        } catch (Throwable) {
            return; // transient error – try again next poll
        }
        if (! $token) {
            return;
        }

        $this->pin = null;
        $this->js('window.plexAuthPopup && window.plexAuthPopup.close()');

        $servers = $plexTv->servers($token);
        if (! $servers) {
            Notification::make()->danger()->title('Din Plex-konto har ingen servere')->send();

            return;
        }
        session()->put('plex-recommender.account_token', $token);
        session()->put('plex-recommender.servers', $servers);

        if (count($servers) === 1) {
            $this->chooseServer($servers[0]['id']);

            return;
        }
        $this->serverOptions = collect($servers)
            ->mapWithKeys(fn ($s) => [$s['id'] => $s['name'].($s['owned'] ? '' : ' (delt med dig)')])
            ->all();
        Notification::make()->info()->title('Vælg hvilken server der skal bruges')->send();
    }

    public function chooseServer(string $id): void
    {
        $token = session('plex-recommender.account_token');
        $server = collect(session('plex-recommender.servers', []))->firstWhere('id', $id);
        if (! $token || ! $server) {
            Notification::make()->danger()->title('Login er udløbet. Log ind igen.')->send();

            return;
        }

        if (! app(PlexTv::class)->connect($server, $token)) {
            Notification::make()->danger()
                ->title("Kunne ikke nå \"{$server['name']}\"")
                ->body('Tjek at serveren er tændt og kan nås fra den maskine, appen kører på.')
                ->persistent()->send();

            return;
        }

        session()->forget(['plex-recommender.account_token', 'plex-recommender.servers']);
        $this->serverOptions = [];
        app(RecommenderService::class)->refresh();
        $this->data['server_url'] = $this->settings()->url();
        $this->data['server_token'] = $this->settings()->token();

        Notification::make()->success()->title("Forbundet til {$server['name']}")->send();
    }

    protected function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Test forbindelse')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->visible(fn () => $this->settings()->connected())
            ->action(function (RecommenderService $svc) {
                try {
                    $name = $svc->plex()->identity();
                    $svc->refresh();
                    $o = $svc->libraryOverview()['stats'];
                    Notification::make()->success()->title("Forbindelsen til {$name} virker")
                        ->body("{$o['movie']['total']} film og {$o['show']['total']} serier fundet.")->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Kunne ikke forbinde')->body($e->getMessage())->send();
                }
            });
    }

    protected function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Afbryd')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->link()
            ->visible(fn () => $this->settings()->isStored('server_token'))
            ->requiresConfirmation()
            ->modalHeading('Afbryd forbindelsen til Plex?')
            ->modalDescription('Du kan altid logge ind igen.')
            ->action(function (RecommenderService $svc) {
                $this->settings()->disconnect();
                $svc->refresh();
                $this->data['server_url'] = $this->settings()->url();
                $this->data['server_token'] = $this->settings()->token();
                Notification::make()->success()->title('Forbindelsen er afbrudt')->send();
            });
    }

    protected function testTmdbAction(): Action
    {
        return Action::make('testTmdb')
            ->label('Test nøgle')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->size('sm')
            ->action(function () {
                $key = trim((string) ($this->data['tmdb_api_key'] ?? ''));
                if ($key === '') {
                    Notification::make()->warning()->title('Indtast en nøgle først')->send();

                    return;
                }
                try {
                    (new \EmilMoe\PlexRecommender\Clients\TmdbClient(app(\Illuminate\Http\Client\Factory::class), $key))->genreNames('movie');
                    Notification::make()->success()->title('Nøglen virker')->body('Husk at gemme.')->send();
                } catch (Throwable) {
                    Notification::make()->danger()->title('Nøglen blev afvist af TMDB')->send();
                }
            });
    }

    protected function testClaudeAction(): Action
    {
        return Action::make('testClaude')
            ->label('Test forbindelse')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->size('sm')
            ->action(function () {
                @set_time_limit(180);
                $d = $this->data;
                $model = trim((string) ($d['claude_model'] ?? '')) ?: null;
                $driver = match ($d['claude_driver'] ?? 'code') {
                    'code' => new ClaudeCodeDriver(trim((string) ($d['claude_binary'] ?? '')) ?: 'claude', $model, 120),
                    'api' => ($key = trim((string) ($d['anthropic_api_key'] ?? ''))) ? new AnthropicApiDriver($key, $model) : null,
                    default => null,
                };
                if (! $driver) {
                    Notification::make()->warning()->title('Claude er slået fra, eller der mangler en API-nøgle')->send();

                    return;
                }
                try {
                    $advisor = new ClaudeAdvisor(app(RecommenderService::class), $this->settings(), app('cache')->store(), $driver);
                    $label = $advisor->test();
                    Notification::make()->success()->title("{$label} virker")->body('Husk at gemme.')->send();
                } catch (ClaudeException $e) {
                    Notification::make()->danger()->title('Claude svarede ikke')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    // ---- helpers ---------------------------------------------------------

    protected function connectionHeading(): string
    {
        $s = $this->settings();

        return match (true) {
            $this->pin !== null => 'Venter på login i Plex-vinduet …',
            $s->connected() => 'Forbundet'.($s->get('server_name') ? ' til '.$s->get('server_name') : ''),
            default => 'Ikke forbundet',
        };
    }

    protected function connectionDescription(): string
    {
        $s = $this->settings();
        if ($this->pin !== null) {
            return 'Log ind i vinduet der åbnede. Kan du ikke se det, så tjek om din browser har blokeret pop op-vinduer.';
        }
        if (! $s->connected()) {
            return 'Tryk "Log ind med Plex" og log ind med din almindelige Plex-konto.';
        }
        $parts = [];
        if ($s->get('account_name')) {
            $parts[] = 'Konto: '.$s->get('account_name');
        }
        $parts[] = 'Adresse: '.$s->url();
        if (! $s->isStored('server_token')) {
            $parts[] = 'Forbindelsen kommer fra serverens konfigurationsfil.';
        }

        return implode(' · ', $parts);
    }

    protected function accountOptions(): array
    {
        if (! $this->settings()->connected()) {
            return [];
        }
        try {
            return app(RecommenderService::class)->plex()->accounts();
        } catch (Throwable) {
            return [];
        }
    }
}
