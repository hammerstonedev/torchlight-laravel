<?php
/**
 * @author Aaron Francis <aaron@hammerstone.dev|https://twitter.com/aarondfrancis>
 */

namespace Torchlight\Tests;

use Torchlight\Block;
use Torchlight\Contracts\PostProcessor;
use Torchlight\Exceptions\ConfigurationException;
use Torchlight\Torchlight;

class PostProcessorTest extends BaseTest
{
    public function getEnvironmentSetUp($app)
    {
        config()->set('torchlight', [
            'theme' => 'material',
            'token' => 'token',
        ]);
    }

    /** @test */
    public function it_runs_post_processors()
    {
        $this->fakeSuccessfulResponse('id');

        Torchlight::addPostProcessors([
            GoodbyePostProcessor::class
        ]);

        $blocks = Torchlight::highlight(
            Block::make('id')->language('php')->code('echo "hello world";')
        );

        $this->assertEquals($blocks[0]->highlighted, '<div class=\'highlighted\'>echo "goodbye world";</div>');
    }

    /** @test */
    public function null_processor_works()
    {
        $this->fakeSuccessfulResponse('id');

        Torchlight::addPostProcessors([
            NullPostProcessor::class
        ]);

        $blocks = Torchlight::highlight(
            Block::make('id')->language('php')->code('echo "hello world";')
        );

        $this->assertEquals($blocks[0]->highlighted, '<div class=\'highlighted\'>echo "hello world";</div>');
    }

    /** @test */
    public function they_run_in_order()
    {
        $this->fakeSuccessfulResponse('id');

        Torchlight::addPostProcessors([
            GoodbyePostProcessor::class,
            GoodbyeCruelPostProcessor::class
        ]);

        $blocks = Torchlight::highlight(
            Block::make('id')->language('php')->code('echo "hello world";')
        );

        $this->assertEquals($blocks[0]->highlighted, '<div class=\'highlighted\'>echo "goodbye cruel world";</div>');
    }

    /** @test */
    public function must_implement_interface()
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Post-processor \'Torchlight\Block\' does not implement Torchlight\Contracts\PostProcessor');

        Torchlight::addPostProcessors([
            Block::class
        ]);
    }
}

class NullPostProcessor implements PostProcessor
{
    public function process(Block $block)
    {
    }
}

class GoodbyePostProcessor implements PostProcessor
{
    public function process(Block $block)
    {
        $block->highlighted = str_replace('hello', 'goodbye', $block->highlighted);
    }
}

class GoodbyeCruelPostProcessor implements PostProcessor
{
    public function process(Block $block)
    {
        $block->highlighted = str_replace('goodbye', 'goodbye cruel', $block->highlighted);
    }
}
