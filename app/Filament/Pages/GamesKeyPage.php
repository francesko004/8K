<?php

namespace App\Filament\Pages;
use Illuminate\Support\HtmlString;
use App\Models\GamesKey;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;

class GamesKeyPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.games-key-page';

    protected static ?string $title = 'Provedor de Jogos';

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public ?array $data = [];
    public ?GamesKey $setting;

    public function mount(): void
    {
        $gamesKey = GamesKey::first();
        if (!empty($gamesKey)) {
            $this->setting = $gamesKey;
            $this->form->fill($this->setting->toArray());
        } else {
            $this->form->fill();
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('API PlayFiver')
                    ->description(new HtmlString('
                        <div style="display: flex; align-items: center;">
                          
                        </div>
                    '))
                    ->schema([
          //              TextInput::make('playfiver_url')
           //                 ->label('URL')
           //                 ->placeholder('Digite aqui a URL')
        //                    ->maxLength(191),
                        TextInput::make('playfiver_secret')
                            ->label('Agent Secret')
                            ->placeholder('Digite aqui o código secreto do agente')
                            ->maxLength(191),
                        TextInput::make('playfiver_code')
                            ->label('Agent Code')
                            ->placeholder('Digite aqui o código do agente')
                            ->maxLength(191),
                        TextInput::make('playfiver_token')
                            ->label('Agent Token')
                            ->placeholder('Digite aqui o token do agente')
                            ->maxLength(191),
                        TextInput::make('playfiver_rtp')
                            ->label('RTP dos usuário 10 a 95')
                            ->placeholder('Digite aqui RTP dos usuário de 10 a 99 Padrão 50')
                            ->maxLength(2)
                            ->rules(['required', 'integer', 'between:10,97', 'regex:/^\d+$/']),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        try {
            if (env('APP_DEMO')) {
                Notification::make()
                    ->title('Atenção')
                    ->body('Você não pode realizar esta alteração na versão demo')
                    ->danger()
                    ->send();
                return;
            }

            $setting = GamesKey::first();
            if (!empty($setting)) {
                if ($setting->update($this->data)) {
                    Notification::make()
                        ->title('Chaves Alteradas')
                        ->body('Suas chaves foram alteradas com sucesso!')
                        ->success()
                        ->send();
                }
            } else {
                if (GamesKey::create($this->data)) {
                    Notification::make()
                        ->title('Chaves Criadas')
                        ->body('Suas chaves foram criadas com sucesso!')
                        ->success()
                        ->send();
                }
            }
        } catch (Halt $exception) {
            Notification::make()
                ->title('Erro ao alterar dados!')
                ->body('Erro ao alterar dados!')
                ->danger()
                ->send();
        }
    }
}
