<?php

namespace CodingSocks\ChunkUploader\Tests\Driver;

use CodingSocks\ChunkUploader\Driver\FlowJsUploadDriver;
use CodingSocks\ChunkUploader\Event\FileUploaded;
use CodingSocks\ChunkUploader\Exception\InternalServerErrorHttpException;
use CodingSocks\ChunkUploader\Tests\TestCase;
use CodingSocks\ChunkUploader\UploadHandler;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class FlowJsUploadDriverTest extends TestCase
{
    /**
     * @var UploadHandler
     */
    private $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('chunk-uploader.uploader', 'flow-js');
        $this->app->make('config')->set('chunk-uploader.sweep', false);
        $this->handler = $this->app->make(UploadHandler::class);

        Storage::fake('local');
        Event::fake();
    }

    public function testDriverInstance()
    {
        $manager = $this->app->make('chunk-uploader.upload-manager');

        $this->assertInstanceOf(FlowJsUploadDriver::class, $manager->driver());
    }

    public function notAllowedRequestMethods()
    {
        return [
            'HEAD' => [Request::METHOD_HEAD],
            'PUT' => [Request::METHOD_PUT],
            'PATCH' => [Request::METHOD_PATCH],
            'DELETE' => [Request::METHOD_DELETE],
            'PURGE' => [Request::METHOD_PURGE],
            'OPTIONS' => [Request::METHOD_OPTIONS],
            'TRACE' => [Request::METHOD_TRACE],
            'CONNECT' => [Request::METHOD_CONNECT],
        ];
    }

    /**
     * @dataProvider notAllowedRequestMethods
     */
    public function testMethodNotAllowed($requestMethod)
    {
        $request = Request::create('', $requestMethod);

        $this->expectException(MethodNotAllowedHttpException::class);

        $this->createTestResponse($this->handler->handle($request));
    }

    public function testResumeWhenChunkDoesNotExists()
    {
        $this->createFakeLocalFile('chunks/200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt', '000-099');

        $request = Request::create('', Request::METHOD_GET, [
            'flowChunkNumber' => 2,
            'flowTotalChunks' => 2,
            'flowChunkSize' => 100,
            'flowTotalSize' => 200,
            'flowIdentifier' => '200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt',
            'flowFilename' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowRelativePath' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowCurrentChunkSize' => 100,
        ]);

        $response = $this->createTestResponse($this->handler->handle($request));
        $response->assertStatus(Response::HTTP_NO_CONTENT);
    }

    public function testResume()
    {
        $this->createFakeLocalFile('chunks/200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt', '000-099');

        $request = Request::create('', Request::METHOD_GET, [
            'flowChunkNumber' => 1,
            'flowTotalChunks' => 2,
            'flowChunkSize' => 100,
            'flowTotalSize' => 200,
            'flowIdentifier' => '200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt',
            'flowFilename' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowRelativePath' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowCurrentChunkSize' => 100,
        ]);

        $response = $this->createTestResponse($this->handler->handle($request));
        $response->assertSuccessful();
    }

    public function testUploadWhenFileParameterIsEmpty()
    {
        $request = Request::create('', Request::METHOD_POST);

        $this->expectException(BadRequestHttpException::class);

        $this->handler->handle($request);
    }

    public function testUploadWhenFileParameterIsInvalid()
    {
        $file = Mockery::mock(UploadedFile::class)
            ->makePartial();
        $file->shouldReceive('isValid')
            ->andReturn(false);

        $request = Request::create('', Request::METHOD_POST, [], [], [
            'file' => $file,
        ]);

        $this->expectException(InternalServerErrorHttpException::class);

        $this->handler->handle($request);
    }

    public function excludedPostParameterProvider()
    {
        return [
            'flowChunkNumber' => ['flowChunkNumber'],
            'flowTotalChunks' => ['flowTotalChunks'],
            'flowChunkSize' => ['flowChunkSize'],
            'flowTotalSize' => ['flowTotalSize'],
            'flowIdentifier' => ['flowIdentifier'],
            'flowFilename' => ['flowFilename'],
            'flowRelativePath' => ['flowRelativePath'],
            'flowCurrentChunkSize' => ['flowCurrentChunkSize'],
        ];
    }

    /**
     * @dataProvider excludedPostParameterProvider
     */
    public function testPostParameterValidation($exclude)
    {
        $arr = [
            'flowChunkNumber' => 1,
            'flowTotalChunks' => 2,
            'flowChunkSize' => 100,
            'flowTotalSize' => 200,
            'flowIdentifier' => '200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt',
            'flowFilename' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowRelativePath' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowCurrentChunkSize' => 100,
        ];

        unset($arr[$exclude]);

        $request = Request::create('', Request::METHOD_POST, $arr, [], [
            'file' => UploadedFile::fake()
                ->create('test.txt', 100),
        ]);

        $this->expectException(ValidationException::class);

        $this->handler->handle($request);
    }

    public function testUploadFirstChunk()
    {
        $file = UploadedFile::fake()->create('test.txt', 100);
        $request = Request::create('', Request::METHOD_POST, [
            'flowChunkNumber' => 1,
            'flowTotalChunks' => 2,
            'flowChunkSize' => 100,
            'flowTotalSize' => 200,
            'flowIdentifier' => '200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt',
            'flowFilename' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowRelativePath' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowCurrentChunkSize' => 100,
        ], [], [
            'file' => $file,
        ]);

        /** @var \Illuminate\Foundation\Testing\TestResponse $response */
        $response = $this->createTestResponse($this->handler->handle($request));
        $response->assertSuccessful();
        $response->assertJson(['done' => 50]);

        Storage::disk('local')->assertExists('chunks/200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt/000-099');

        Event::assertNotDispatched(FileUploaded::class, function ($event) use ($file) {
            return $event->file = $file->hashName('merged');
        });
    }

    public function testUploadFirstChunkWithCallback()
    {
        $file = UploadedFile::fake()->create('test.txt', 100);
        $request = Request::create('', Request::METHOD_POST, [
            'flowChunkNumber' => 1,
            'flowTotalChunks' => 2,
            'flowChunkSize' => 100,
            'flowTotalSize' => 200,
            'flowIdentifier' => '200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt',
            'flowFilename' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowRelativePath' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowCurrentChunkSize' => 100,
        ], [], [
            'file' => $file,
        ]);

        $callback = $this->createClosureMock($this->never());

        $this->handler->handle($request, $callback);

        Event::assertNotDispatched(FileUploaded::class, function ($event) use ($file) {
            return $event->file = $file->hashName('merged');
        });
    }

    public function testUploadLastChunk()
    {
        $this->createFakeLocalFile('chunks/200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt', '000-099');

        $file = UploadedFile::fake()->create('test.txt', 100);
        $request = Request::create('', Request::METHOD_POST, [
            'flowChunkNumber' => 2,
            'flowTotalChunks' => 2,
            'flowChunkSize' => 100,
            'flowTotalSize' => 200,
            'flowIdentifier' => '200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt',
            'flowFilename' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowRelativePath' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowCurrentChunkSize' => 100,
        ], [], [
            'file' => $file,
        ]);

        /** @var \Illuminate\Foundation\Testing\TestResponse $response */
        $response = $this->createTestResponse($this->handler->handle($request));
        $response->assertSuccessful();
        $response->assertJson(['done' => 100]);

        Storage::disk('local')->assertExists('chunks/200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt/100-199');
        Storage::disk('local')->assertExists($file->hashName('merged'));

        Event::assertDispatched(FileUploaded::class, function ($event) use ($file) {
            return $event->file = $file->hashName('merged');
        });
    }

    public function testUploadLastChunkWithCallback()
    {
        $this->createFakeLocalFile('chunks/200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt', '000-099');

        $file = UploadedFile::fake()->create('test.txt', 100);
        $request = Request::create('', Request::METHOD_POST, [
            'flowChunkNumber' => 2,
            'flowTotalChunks' => 2,
            'flowChunkSize' => 100,
            'flowTotalSize' => 200,
            'flowIdentifier' => '200-0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zftxt',
            'flowFilename' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowRelativePath' => '0jWZTB1ZDfRQU6VTcXy0mJnL9xKMeEz3HoSPU0Zf.txt',
            'flowCurrentChunkSize' => 100,
        ], [], [
            'file' => $file,
        ]);

        $callback = $this->createClosureMock(
            $this->once(),
            'local',
            $file->hashName('merged')
        );

        $this->handler->handle($request, $callback);

        Event::assertDispatched(FileUploaded::class, function ($event) use ($file) {
            return $event->file = $file->hashName('merged');
        });
    }
}
