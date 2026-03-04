<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ScanControllerTest extends WebTestCase
{
    private const TEST_USER_GITHUB_ID = 999999;

    /**
     * Creates an HTTP client authenticated as a test user (required for /api routes).
     * With SQLite in-memory, each test can get a fresh DB so we ensure schema exists every time.
     */
    private function createAuthenticatedClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $schemaTool = new SchemaTool($em);
        $schemaTool->updateSchema($em->getMetadataFactory()->getAllMetadata());

        $user = $em->getRepository(User::class)->findOneBy(['githubId' => self::TEST_USER_GITHUB_ID]);
        if (!$user) {
            $user = new User();
            $user->setGithubId(self::TEST_USER_GITHUB_ID);
            $user->setUsername('testuser');
            $user->setGithubToken('test-token');
            $em->persist($user);
            $em->flush();
        }
        $client->loginUser($user);

        return $client;
    }

    public function testCreateReturns400WhenNoRepositoryUrlNorProjectId(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request(
            'POST',
            '/api/scans',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('repositoryUrl', $data['error']);
    }

    public function testCreateReturns400WhenPayloadHasEmptyRepositoryUrlAndNoProjectId(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request(
            'POST',
            '/api/scans',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['repositoryUrl' => ''])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testFromArchiveReturns400WhenNoFile(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('POST', '/api/scans/from-archive', [], []);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('ZIP', $data['error']);
    }

    public function testFromArchiveReturns400WhenFileIsNotZip(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'securescan');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'dummy content');
        $upload = new UploadedFile($tmpFile, 'archive.tar', 'application/x-tar', \UPLOAD_ERR_OK, true);

        $client = $this->createAuthenticatedClient();
        $client->request('POST', '/api/scans/from-archive', [], ['archive' => $upload]);

        @unlink($tmpFile);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('.zip', $data['error']);
    }
}
