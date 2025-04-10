<?php

namespace App\Filament\Pages;

use App\Models\Gateway;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Jackiedo\DotenvEditor\Facades\DotenvEditor;
use Illuminate\Support\HtmlString;


class GatewayPage extends Page
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.gateway-page';

    public ?array $data = [];
    public Gateway $setting;

    /**
     * @dev @victormsalatiel
     * @return bool
     */
    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin');
    }

    /**
     * @return void
     */
    public function mount(): void
    {
        $gateway = Gateway::first();
        if(!empty($gateway)) {
            $this->setting = $gateway;
            $this->form->fill($this->setting->toArray());
        }else{
            $this->form->fill();
        }
    }

    /**
     * @param Form $form
     * @return Form
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                             Section::make('DusPay')
                             ->description(new HtmlString('
                                <div style="display: flex; align-items: center;">
                                    Precisa de uma conta DusPay? Registre-se agora mesmo:
                                    <a class="dark:text-white"
                                    style="
                                            font-size: 14px;
                                            font-weight: 600;
                                            width: 127px;
                                            display: flex;
                                            background-color: #00b54d;
                                            padding: 10px;
                                            border-radius: 11px;
                                            justify-content: center;
                                            margin-left: 10px;
                                    "
                                    href="https://duspay.com.br/"
                                    target="_blank">
                                        Dashboard
                                    </a>
                                    <a class="dark:text-white"
                                    style="
                                            font-size: 14px;
                                            font-weight: 600;
                                            width: 127px;
                                            display: flex;
                                            background-color: #00b54d;
                                            padding: 10px;
                                            border-radius: 11px;
                                            justify-content: center;
                                            margin-left: 10px;
                                    "
                                    href="https://wa.me/5511918229931"
                                    target="_blank">
                                        Gerente
                                    </a>
                                </div>
                    '),)
                    ->schema([
                        TextInput::make('suitpay_uri')
                            ->label('Client URI')
                            ->placeholder('Digite a url da api')
                            ->maxLength(191)
                            ->columnSpanFull(),
                        TextInput::make('suitpay_cliente_id')
                            ->label('Client ID')
                            ->placeholder('Digite o client ID')
                            ->maxLength(191)
                            ->columnSpanFull(),
                        TextInput::make('suitpay_cliente_secret')
                            ->label('Client Secret')
                            ->placeholder('Digite o client secret')
                            ->maxLength(191)
                            ->columnSpanFull(),
                    ])
            ])
            ->statePath('data');
    }


    /**
     * @return void
     */
    public function submit(): void
    {
        try {
            if(env('APP_DEMO')) {
                Notification::make()
                    ->title('Atenção')
                    ->body('Você não pode realizar está alteração na versão demo')
                    ->danger()
                    ->send();
                return;
            }

            $setting = Gateway::first();
            if(!empty($setting)) {
                if($setting->update($this->data)) {
                    Notification::make()
                        ->title('Chaves Alteradas')
                        ->body('Suas chaves foram alteradas com sucesso!')
                        ->success()
                        ->send();
                }
            }else{
                if(Gateway::create($this->data)) {
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
