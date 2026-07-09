<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TemplateResource\Pages;
use ColorrageAR\Autoresponder\Models\Template;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Support\Colors\Color;
use Illuminate\Support\HtmlString;

class TemplateResource extends Resource
{
    protected static ?string $model = Template::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-envelope';
    protected static string | \UnitEnum | null $navigationGroup = 'Autoresponder';
    protected static ?string $navigationLabel = 'Email Templates';
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template Details')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->label('Template Name')
                        ->helperText('Internal name (e.g., "Welcome Email", "Promo September")'),

                    Select::make('locale')
                        ->options(config('autoresponder.languages', ['en' => 'English', 'ro' => 'Romanian']))
                        ->default('ro')
                        ->required()
                        ->label('Language'),

                    TextInput::make('subject')
                        ->required()
                        ->maxLength(500)
                        ->label('Email Subject')
                        ->helperText('You can use tokens like ##client.name## in the subject')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Email Body')
                ->schema([
                    RichEditor::make('body')
                        ->required()
                        ->label('HTML Content')
                        ->toolbarButtons([
                            'bold', 'italic', 'underline', 'strike', 'h2', 'h3',
                            'bulletList', 'orderedList', 'link', 'codeBlock', 'blockquote',
                        ])
                        ->helperText('Insert tokens like ##client.name## for dynamic content.')
                        ->columnSpanFull(),
                ]),

            Section::make('📝 Available Tokens - Copy & Paste')
                ->description('Use these tokens in your email subject and body. They will be automatically replaced with actual client data when sending.')
                ->schema([
                    Placeholder::make("tokens_help")
                        ->label('')
                        ->content(function () {
                            // In a real scenario, this would come from a service.
                            // For the stub, we provide common CMR Management tokens.
                            $tokens = [
                                '##client.name##' => 'Client full name',
                                '##client.company##' => 'Company name',
                                '##client.email##' => 'Client email address',
                                '##config.app.name##' => 'Application name',
                            ];

                            $html = '<div style="font-family: monospace; font-size: 13px; background: #f9fafb; padding: 12px; border-radius: 8px;">';
                            foreach ($tokens as $token => $description) {
                                $html .= "<div style='margin: 8px 0; padding: 6px; background: white; border-left: 3px solid #2563eb; border-radius: 4px;'>";
                                $html .= "<code style='color: #2563eb; font-weight: 600; font-size: 14px;'>$token</code>";
                                $html .= " <span style='color: #6b7280;'>→</span> ";
                                $html .= "<span style='color: #374151;'>$description</span>";
                                $html .= "</div>";
                            }
                            $html .= '</div>';
                            return new HtmlString($html);
                        }),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Template Name'),

                TextColumn::make('subject')
                    ->limit(50)
                    ->searchable()
                    ->label('Subject'),

                TextColumn::make('locale')
                    ->sortable()
                    ->label('Language')
                    ->badge()
                    ->formatStateUsing(fn ($state) => strtoupper($state))
                    ->color(fn ($state) => match($state) {
                        'ro' => 'primary',
                        'en' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->label('Created'),
            ])
            ->actions([
                Action::make('preview')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading(fn ($record) => "Preview: {$record->name}")
                    ->modalContent(fn ($record) => view('vendor.autoresponder.email-preview', [
                        'subject' => $record->subject,
                        'body' => $record->body,
                    ]))
                    ->modalWidth('4xl')
                    ->slideOver(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTemplates::route('/'),
            'create' => Pages\CreateTemplate::route('/create'),
            'edit' => Pages\EditTemplate::route('/{record}/edit'),
        ];
    }
}

