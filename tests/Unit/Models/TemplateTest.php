<?php

namespace ColorrageAR\Autoresponder\Tests\Unit\Models;

use ColorrageAR\Autoresponder\Models\Template;
use ColorrageAR\Autoresponder\Tests\TestCase;

class TemplateTest extends TestCase
{
    public function test_template_is_created(): void
    {
        $tpl = Template::create([
            'name' => 'Welcome',
            'subject' => 'Hello {{name}}',
            'body' => '<p>Welcome aboard</p>',
            'locale' => 'en',
        ]);

        $this->assertNotNull($tpl->id);
        $this->assertEquals('Welcome [en]', $tpl->display_name);
        $this->assertEquals('Welcome', $tpl->name);
        $this->assertEquals('en', $tpl->locale);
    }

    public function test_scope_locale(): void
    {
        Template::create([
            'name' => 'EN Template', 'subject' => 'EN', 'body' => '<p>EN</p>', 'locale' => 'en',
        ]);
        Template::create([
            'name' => 'DE Template', 'subject' => 'DE', 'body' => '<p>DE</p>', 'locale' => 'de',
        ]);

        $this->assertCount(1, Template::locale('en')->get());
        $this->assertCount(1, Template::locale('de')->get());
    }
}
