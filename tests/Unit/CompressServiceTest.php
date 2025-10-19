<?php

namespace Tests\Unit;

use App\Services\Ilovepdf\CompressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CompressServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompressService $service;
    private $mockIlovepdf;
    private $mockTask;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Мокаем конфиг
        Config::set('services.ilovepdf.public_key', 'test_public_key');
        Config::set('services.ilovepdf.secret_key', 'test_secret_key');
        Config::set('services.ilovepdf.compress_mode', 'recommended');
        Config::set('services.ilovepdf.timeout', 120);

        // Мокаем SDK
        $this->mockTask = Mockery::mock('Ilovepdf\Task\CompressTask');
        $this->mockIlovepdf = Mockery::mock('Ilovepdf\Ilovepdf');
        
        $this->service = new CompressService('test_public_key', 'test_secret_key');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_compress_success(): void
    {
        // Arrange
        $inputPath = '/tmp/test_input.pdf';
        $outputPath = '/tmp/test_output.pdf';
        $inputSize = 1024;
        $outputSize = 512;

        // Создаём временные файлы
        file_put_contents($inputPath, str_repeat('x', $inputSize));
        
        // Мокаем SDK поведение
        $this->mockTask->shouldReceive('setCompressionLevel')
            ->with('recommended')
            ->once()
            ->andReturnSelf();
        
        $this->mockTask->shouldReceive('addFile')
            ->with($inputPath)
            ->once()
            ->andReturnSelf();
            
        $this->mockTask->shouldReceive('execute')
            ->once()
            ->andReturnSelf();
            
        $this->mockTask->shouldReceive('download')
            ->with('/tmp')
            ->once();

        // Мокаем Ilovepdf конструктор и newTask
        $this->mockIlovepdf->shouldReceive('newTask')
            ->with('compress')
            ->once()
            ->andReturn($this->mockTask);

        // Создаём мок файла после download
        file_put_contents($outputPath, str_repeat('y', $outputSize));

        // Act & Assert
        $result = $this->service->compress($inputPath, $outputPath);
        
        $this->assertEquals($inputSize, $result['size_before']);
        $this->assertEquals($outputSize, $result['size_after']);
        $this->assertEquals($inputSize - $outputSize, $result['reduced_bytes']);

        // Cleanup
        @unlink($inputPath);
        @unlink($outputPath);
    }

    public function test_compress_file_not_found(): void
    {
        // Arrange
        $inputPath = '/tmp/nonexistent.pdf';
        $outputPath = '/tmp/output.pdf';

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Input file not found: ' . $inputPath);
        
        $this->service->compress($inputPath, $outputPath);
    }

    public function test_compress_retry_on_failure(): void
    {
        // Arrange
        $inputPath = '/tmp/test_input.pdf';
        $outputPath = '/tmp/test_output.pdf';
        $inputSize = 1024;

        file_put_contents($inputPath, str_repeat('x', $inputSize));

        // Мокаем SDK чтобы падал первые 2 раза, потом успех
        $this->mockTask->shouldReceive('setCompressionLevel')
            ->with('recommended')
            ->times(3)
            ->andReturnSelf();
        
        $this->mockTask->shouldReceive('addFile')
            ->with($inputPath)
            ->times(3)
            ->andReturnSelf();
            
        $this->mockTask->shouldReceive('execute')
            ->twice()
            ->andThrow(new \Exception('Network error'));
            
        $this->mockTask->shouldReceive('execute')
            ->once()
            ->andReturnSelf();
            
        $this->mockTask->shouldReceive('download')
            ->with('/tmp')
            ->once();

        $this->mockIlovepdf->shouldReceive('newTask')
            ->with('compress')
            ->times(3)
            ->andReturn($this->mockTask);

        // Создаём файл после успешного download
        file_put_contents($outputPath, str_repeat('y', 512));

        // Act
        $result = $this->service->compress($inputPath, $outputPath);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('size_before', $result);
        $this->assertArrayHasKey('size_after', $result);
        $this->assertArrayHasKey('reduced_bytes', $result);

        // Cleanup
        @unlink($inputPath);
        @unlink($outputPath);
    }

    public function test_compress_final_failure_after_retries(): void
    {
        // Arrange
        $inputPath = '/tmp/test_input.pdf';
        $outputPath = '/tmp/test_output.pdf';
        $inputSize = 1024;

        file_put_contents($inputPath, str_repeat('x', $inputSize));

        // Мокаем SDK чтобы падал все 3 раза
        $this->mockTask->shouldReceive('setCompressionLevel')
            ->with('recommended')
            ->times(3)
            ->andReturnSelf();
        
        $this->mockTask->shouldReceive('addFile')
            ->with($inputPath)
            ->times(3)
            ->andReturnSelf();
            
        $this->mockTask->shouldReceive('execute')
            ->times(3)
            ->andThrow(new \Exception('Persistent network error'));

        $this->mockIlovepdf->shouldReceive('newTask')
            ->with('compress')
            ->times(3)
            ->andReturn($this->mockTask);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Не удалось сжать PDF. Попробуйте позже.');
        
        $this->service->compress($inputPath, $outputPath);

        // Cleanup
        @unlink($inputPath);
    }
}
