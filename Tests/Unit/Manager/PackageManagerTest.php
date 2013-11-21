<?php

namespace Oro\Bundle\DistributionBundle\Tests\Unit\Manager;

use Composer\Composer;
use Composer\Installer;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ArrayRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableArrayRepository;
use Composer\Installer\InstallationManager;
use Oro\Bundle\DistributionBundle\Manager\PackageManager;
use Oro\Bundle\DistributionBundle\Test\PhpUnit\Helper\MockHelperTrait;
use Oro\Bundle\DistributionBundle\Script\Runner;

class PackageManagerTest extends \PHPUnit_Framework_TestCase
{
    use MockHelperTrait;

    /**
     * @test
     */
    public function shouldBeConstructedWithComposerAndInstallerAndIOAndScriptRunner()
    {
        $this->createPackageManager();
    }

    /**
     * @test
     */
    public function shouldReturnInstalledPackages()
    {
        $composer = $this->createComposerMock();
        $repositoryManagerMock = $this->createRepositoryManagerMock();

        $composer->expects($this->once())
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManagerMock));

        $localRepository = new WritableArrayRepository(
            $installedPackages = [$this->getPackage('my/package', 1)]
        );
        $repositoryManagerMock->expects($this->once())
            ->method('getLocalRepository')
            ->will($this->returnValue($localRepository));

        $manager = $this->createPackageManager($composer);
        $this->assertEquals($installedPackages, $manager->getInstalled());
    }

    /**
     * @test
     */
    public function shouldReturnAvailablePackages()
    {
        $composer = $this->createComposerMock();
        $repositoryManagerMock = $this->createRepositoryManagerMock();

        // Local repo with installed packages
        $installedPackages = [$this->getPackage('name1', 1), $this->getPackage('name5', 1)];
        $localRepository = new WritableArrayRepository($installedPackages);

        // Remote repos
        $duplicatedPackageName = uniqid();
        $composerRepositoryMock = $this->createComposerRepositoryMock();
        $composerRepositoryWithoutProvidersMock = $this->createComposerRepositoryMock();
        $anyRepositoryExceptComposerRepository = new ArrayRepository(
            [$this->getPackage('name4', 1), $this->getPackage($duplicatedPackageName, 1)]
        );

        // Get remote repos
        $composer->expects($this->exactly(2))
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManagerMock));

        $repositoryManagerMock->expects($this->once())
            ->method('getRepositories')
            ->will(
                $this->returnValue(
                    [
                        $composerRepositoryMock,
                        $composerRepositoryWithoutProvidersMock,
                        $anyRepositoryExceptComposerRepository
                    ]
                )
            );

        // Get local repo
        $repositoryManagerMock->expects($this->once())
            ->method('getLocalRepository')
            ->will($this->returnValue($localRepository));

        // Fetch available packages configuration
        // from composer repo
        $composerRepositoryMock->expects($this->once())
            ->method('hasProviders')
            ->will($this->returnValue(true));

        $composerRepositoryMock->expects($this->once())
            ->method('getProviderNames')
            ->will($this->returnValue(['name1', 'name2']));

        // from composer repo without providers
        $composerRepositoryWithoutProvidersMock->expects($this->once())
            ->method('hasProviders')
            ->will($this->returnValue(false));

        $availablePackage1 = $this->getPackage('name3', 1);
        $availablePackage2 = $this->getPackage($duplicatedPackageName, 1);
        $composerRepositoryWithoutProvidersMock->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue([$availablePackage1, $availablePackage2]));

        // Ready Steady Go!
        $manager = $this->createPackageManager($composer);

        $this->assertEquals(
            ['name2', 'name3', $duplicatedPackageName, 'name4'],
            $manager->getAvailable()
        );
    }

    /**
     * @test
     */
    public function shouldReturnPackageRequirementsWithoutPlatformRequirements()
    {
        $expectedRequirements = ['requirement1', 'requirement2'];
        $platformRequirement = 'php-64bit';
        $packageName = 'vendor/package';
        $packageVersion = '*';

        // guard. Platform requirement is the one that matches following regexp
        $this->assertRegExp(PlatformRepository::PLATFORM_PACKAGE_REGEX, $platformRequirement);

        $requirementLinkMock1 = $this->createComposerPackageLinkMock();
        $requirementLinkMock2 = $this->createComposerPackageLinkMock();
        $platformRequirementLinkMock = $this->createComposerPackageLinkMock();

        // non platform requirements
        $requirementLinkMock1->expects($this->exactly(2))
            ->method('getTarget')
            ->will($this->returnValue($expectedRequirements[0]));

        $requirementLinkMock2->expects($this->exactly(2))
            ->method('getTarget')
            ->will($this->returnValue($expectedRequirements[1]));

        // platform dependency
        $platformRequirementLinkMock->expects($this->once())
            ->method('getTarget')
            ->will($this->returnValue($platformRequirement));

        // package mock configuration
        $packageMock = $this->createPackageMock();
        $packageMock->expects($this->once())
            ->method('getRequires')
            ->will($this->returnValue([$requirementLinkMock1, $requirementLinkMock2, $platformRequirementLinkMock]));
        $packageMock->expects($this->any())
            ->method('getName')
            ->will($this->returnValue($packageName));
        $packageMock->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue($packageVersion));
        $packageMock->expects($this->once())
            ->method('getNames')
            ->will($this->returnValue([$packageName]));
        $packageMock->expects($this->once())
            ->method('getStability')
            ->will($this->returnValue('stable'));

        // composer and repository config
        $composer = $this->createComposerMock();
        $repositoryManagerMock = $this->createRepositoryManagerMock();
        $composer->expects($this->once())
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManagerMock));

        $localRepository = new WritableArrayRepository([$packageMock]);

        $repositoryManagerMock->expects($this->once())
            ->method('getRepositories')
            ->will($this->returnValue([$localRepository]));


        $manager = $this->createPackageManager($composer);

        $this->assertEquals($expectedRequirements, $manager->getRequirements($packageName, $packageVersion));
    }

    /**
     * @test
     */
    public function shouldReturnTrueForInstalledPackagesFalseOtherwise()
    {
        $notInstalledPackageName = 'not-installed/package';

        $composer = $this->createComposerMock();
        $repositoryManagerMock = $this->createRepositoryManagerMock();

        $installedPackage = $this->getPackage('installed/package', 1);
        $localRepository = new WritableArrayRepository([$installedPackage]);

        $composer->expects($this->exactly(2))
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManagerMock));

        $repositoryManagerMock->expects($this->exactly(2))
            ->method('getLocalRepository')
            ->will($this->returnValue($localRepository));

        $manager = $this->createPackageManager($composer);

        $this->assertFalse($manager->isPackageInstalled($notInstalledPackageName));
        $this->assertTrue($manager->isPackageInstalled($installedPackage->getName()));
    }

    /**
     * @test
     */
    public function shouldRunInstallerAddPackageToComposerJsonAndUpdateRootPackage()
    {
        $newPackageName = 'new-vendor/new-package';
        $newPackageVersion = 'v3';
        $newPackage = $this->getPackage($newPackageName, $newPackageVersion);

        // temporary composer.json data
        $composerJsonData = [
            'require' => [
                'vendor1/package1' => 'v1',
                'vendor2/package2' => 'v2',
            ]
        ];

        $expectedJsonData = $composerJsonData;
        $expectedJsonData['require'][$newPackageName] = $newPackageVersion;

        $tempComposerJson = tempnam(sys_get_temp_dir(), 'composer.json');
        file_put_contents($tempComposerJson, json_encode($composerJsonData));

        // composer and repository
        $composer = $this->createComposerMock();
        $repositoryManager = $this->createRepositoryManagerMock();
        $localRepository = new WritableArrayRepository($installedPackages = [$newPackage]);

        $composer->expects($this->any())
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManager));
        $repositoryManager->expects($this->any())
            ->method('getLocalRepository')
            ->will($this->returnValue($localRepository));
        $repositoryManager->expects($this->once())
            ->method('getRepositories')
            ->will($this->returnValue([$localRepository]));

        // root package (project composer.json)
        $rootPackageMock = $this->createRootPackageMock();
        $rootPackageMock->expects($this->once())
            ->method('setRequires');
        $rootPackageMock->expects($this->once())
            ->method('getName');
        $rootPackageMock->expects($this->once())
            ->method('getPrettyVersion');

        $composer->expects($this->once())
            ->method('getPackage')
            ->will($this->returnValue($rootPackageMock));

        $composerInstaller = $this->prepareInstallerMock($newPackage);
        $manager = $this->createPackageManager($composer, $composerInstaller, null, null, $tempComposerJson);
        $manager->install($newPackage->getName());

        $updatedComposerData = json_decode(file_get_contents($tempComposerJson), true);
        unlink($tempComposerJson);

        $this->assertEquals($expectedJsonData, $updatedComposerData);
    }

    /**
     * @test
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Cannot find package my/package
     */
    public function throwExceptionWhenCanNotFindPreferredPackage()
    {
        $composer = $this->createComposerMock();

        $repositoryManager = $this->createRepositoryManagerMock();
        $composer->expects($this->once())
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManager));

        $repository = new ArrayRepository();
        $repositoryManager->expects($this->once())
            ->method('getRepositories')
            ->will($this->returnValue([$repository]));

        $manager = $this->createPackageManager($composer);

        $manager->getPreferredPackage('my/package');
    }

    /**
     * @test
     */
    public function shouldReturnPreferredPackageForOnePackage()
    {
        $composer = $this->createComposerMock();

        $repositoryManager = $this->createRepositoryManagerMock();
        $composer->expects($this->once())
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManager));

        $repository = new ArrayRepository([$package = $this->getPackage('my/package', '1')]);
        $repositoryManager->expects($this->once())
            ->method('getRepositories')
            ->will($this->returnValue([$repository]));

        $manager = $this->createPackageManager($composer);

        $this->assertSame($package, $manager->getPreferredPackage($package->getName(), $package->getVersion()));
    }

    /**
     * @test
     */
    public function shouldReturnPreferredPackageForPackages()
    {
        $composer = $this->createComposerMock();

        $repositoryManager = $this->createRepositoryManagerMock();
        $composer->expects($this->once())
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManager));

        $repository = new ArrayRepository(
            [
                $package1 = $this->getPackage('my/package', '1'),
                $package2 = $this->getPackage('my/package', '2'),
            ]
        );
        $repositoryManager->expects($this->once())
            ->method('getRepositories')
            ->will($this->returnValue([$repository]));

        $manager = $this->createPackageManager($composer);

        $this->assertSame($package1, $manager->getPreferredPackage($package1->getName(), $package1->getVersion()));
    }

    /**
     * @test
     */
    public function shouldReturnNewestPreferredPackage()
    {
        $composer = $this->createComposerMock();

        $repositoryManager = $this->createRepositoryManagerMock();
        $composer->expects($this->once())
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManager));

        $packageName = 'my/package';
        $repository = new ArrayRepository(
            [
                $outdatedPackage = $this->getPackage($packageName, '1'),
                $freshPackage = $this->getPackage($packageName, '2'),
            ]
        );
        $repositoryManager->expects($this->once())
            ->method('getRepositories')
            ->will($this->returnValue([$repository]));

        $manager = $this->createPackageManager($composer);

        $this->assertSame($freshPackage, $manager->getPreferredPackage($packageName));
    }

    /**
     * @test
     */
    public function shouldUninstallViaInstallationManagerAndUpdateComposerJson()
    {
        $packageNamesToBeRemoved = ['vendor2/package2', 'vendor3/package3'];
        $composerJsonData = [
            'require' => [
                'vendor1/package1' => 'v1',
                $packageNamesToBeRemoved[0] => 'v2',
                $packageNamesToBeRemoved[1] => 'v2',
                'vendor4/package4' => 'v3',
            ]
        ];
        $expectedJsonData = $composerJsonData;
        unset($expectedJsonData['require'][$packageNamesToBeRemoved[0]]);
        unset($expectedJsonData['require'][$packageNamesToBeRemoved[1]]);

        $tempComposerJson = tempnam(sys_get_temp_dir(), 'composer.json');
        file_put_contents($tempComposerJson, json_encode($composerJsonData));

        // composer and repository
        $composer = $this->createComposerMock();
        $repositoryManager = $this->createRepositoryManagerMock();
        $installationManager = $this->createInstallationManagerMock();
        $localRepository = new WritableArrayRepository($installedPackages = [
            $this->getPackage($packageNamesToBeRemoved[0], 'v2'),
            $this->getPackage($packageNamesToBeRemoved[1], 'v2')
        ]);

        $composer->expects($this->any())
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManager));
        $repositoryManager->expects($this->any())
            ->method('getLocalRepository')
            ->will($this->returnValue($localRepository));
        $composer->expects($this->any())
            ->method('getInstallationManager')
            ->will($this->returnValue($installationManager));

        // Uninstallation
        $installationManager->expects($this->exactly(count($packageNamesToBeRemoved)))
            ->method('uninstall')
            ->with(
                $this->equalTo($localRepository),
                $this->isInstanceOf('Composer\DependencyResolver\Operation\UninstallOperation')
            );

        // run uninstall scripts
        $runner = $this->createScriptRunnerMock();
        $runner->expects($this->at(0))
            ->method('uninstall')
            ->with($installedPackages[0]);
        $runner->expects($this->at(1))
            ->method('uninstall')
            ->with($installedPackages[1]);

        // Ready Steady Go!
        $manager = $this->createPackageManager($composer, null, null, $runner, $tempComposerJson);
        $manager->uninstall($packageNamesToBeRemoved);

        $updatedComposerData = json_decode(file_get_contents($tempComposerJson), true);
        unlink($tempComposerJson);

        $this->assertEquals($expectedJsonData, $updatedComposerData);
    }

    /**
     * @test
     */
    public function shouldReturnDependentsListRecursively()
    {
        $packageName = 'vendor/package';
        $expectedDependents = ['vendor1/package1', 'vendor2/package2', 'vendor3/package3'];
        $packageLink = $this->createComposerPackageLinkMock();
        $packageLink->expects($this->any())
            ->method('getTarget')
            ->will($this->returnValue($packageName));

        $packageLink1 = $this->createComposerPackageLinkMock();
        $packageLink1->expects($this->any())
            ->method('getTarget')
            ->will($this->returnValue($expectedDependents[0]));

        $package1 = $this->createPackageMock();
        $package1->expects($this->any())
            ->method('getName')
            ->will($this->returnValue($expectedDependents[0]));
        $package1->expects($this->any())
            ->method('getRequires')
            ->will($this->returnValue([$packageLink]));
        $package1->expects($this->any())
            ->method('getDevRequires')
            ->will($this->returnValue([]));

        $package2 = $this->createPackageMock();
        $package2->expects($this->any())
            ->method('getName')
            ->will($this->returnValue($expectedDependents[1]));
        $package2->expects($this->any())
            ->method('getRequires')
            ->will($this->returnValue([$packageLink]));
        $package2->expects($this->any())
            ->method('getDevRequires')
            ->will($this->returnValue([$packageLink]));

        $package3 = $this->createPackageMock();
        $package3->expects($this->any())
            ->method('getName')
            ->will($this->returnValue($expectedDependents[2]));
        $package3->expects($this->any())
            ->method('getRequires')
            ->will($this->returnValue([]));
        $package3->expects($this->any())
            ->method('getDevRequires')
            ->will($this->returnValue([$packageLink1]));

        $composer = $this->createComposerMock();
        $repositoryManager = $this->createRepositoryManagerMock();
        $localRepository = new WritableArrayRepository([$package1, $package2, $package3]);

        $composer->expects($this->any())
            ->method('getRepositoryManager')
            ->will($this->returnValue($repositoryManager));
        $repositoryManager->expects($this->any())
            ->method('getLocalRepository')
            ->will($this->returnValue($localRepository));

        $manager = $this->createPackageManager($composer);
        $dependents = $manager->getDependents($packageName);
        sort($expectedDependents);
        sort($dependents);

        $this->assertEquals($expectedDependents, $dependents);
    }

    /**
     * @param string $name
     * @param string $version
     * @param string $class
     * @return PackageInterface
     */
    protected function getPackage($name, $version, $class = 'Composer\Package\Package')
    {
        static $parser;
        if (!$parser) {
            $parser = new VersionParser();
        }

        return new $class($name, $parser->normalize($version), $version);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|Composer
     */
    protected function createComposerMock()
    {
        return $this->createConstructorLessMock('Composer\Composer');
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|RepositoryManager
     */
    protected function createRepositoryManagerMock()
    {
        return $this->createConstructorLessMock('Composer\Repository\RepositoryManager');
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|ComposerRepository
     */
    protected function createComposerRepositoryMock()
    {
        return $this->createConstructorLessMock('Composer\Repository\ComposerRepository');
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|Installer
     */
    protected function createComposerInstallerMock()
    {
        return $this->createConstructorLessMock('Composer\Installer');
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|Link
     */
    protected function createComposerPackageLinkMock()
    {
        return $this->createConstructorLessMock('Composer\Package\Link');
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|RootPackageInterface
     */
    protected function createRootPackageMock()
    {
        return $this->createConstructorLessMock('Composer\Package\RootPackageInterface');
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|IOInterface
     */
    protected function createComposerIO()
    {
        return new NullIO();
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|Runner
     */
    protected function createScriptRunnerMock()
    {
        return $this->createConstructorLessMock('Oro\Bundle\DistributionBundle\Script\Runner');
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|InstallationManager
     */
    protected function createInstallationManagerMock()
    {
        return $this->createConstructorLessMock('Composer\Installer\InstallationManager');
    }

    /**
     * @param Composer $composer
     * @param Installer $installer
     * @param IOInterface $io
     * @param Runner $scriptRunner
     * @param null $pathToComposerJson
     *
     * @return PackageManager
     */
    protected function createPackageManager(
        Composer $composer = null,
        Installer $installer = null,
        IOInterface $io = null,
        Runner $scriptRunner = null,
        $pathToComposerJson = null
    ) {
        if (!$composer) {
            $composer = $this->createComposerMock();
        }
        if (!$installer) {
            $installer = $this->createComposerInstallerMock();
        }
        if (!$io) {
            $io = $this->createComposerIO();
        }
        if (!$scriptRunner) {
            $scriptRunner = $this->createScriptRunnerMock();
        }

        return new PackageManager($composer, $installer, $io, $scriptRunner, $pathToComposerJson);
    }

    /**
     * @param PackageInterface $package
     * @return Installer|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function prepareInstallerMock($package)
    {
        $composerInstaller = $this->createComposerInstallerMock();
        $composerInstaller->expects($this->once())->method('setDryRun')->with($this->equalTo(false))->will(
            $this->returnSelf()
        );
        $composerInstaller->expects($this->once())->method('setVerbose')->with($this->equalTo(false))->will(
            $this->returnSelf()
        );
        $composerInstaller->expects($this->once())->method('setPreferSource')->with($this->equalTo(false))->will(
            $this->returnSelf()
        );
        $composerInstaller->expects($this->once())->method('setPreferDist')->with($this->equalTo(true))->will(
            $this->returnSelf()
        );
        $composerInstaller->expects($this->once())->method('setDevMode')->with($this->equalTo(false))->will(
            $this->returnSelf()
        );
        $composerInstaller->expects($this->once())->method('setRunScripts')->with($this->equalTo(true))->will(
            $this->returnSelf()
        );
        $composerInstaller->expects($this->once())->method('setUpdate')->with($this->equalTo(true))->will(
            $this->returnSelf()
        );
        $composerInstaller->expects($this->once())->method('setUpdateWhitelist')->with(
            $this->equalTo([$package->getName()])
        )->will($this->returnSelf());
        $composerInstaller->expects($this->once())->method('setOptimizeAutoloader')->with(
            $this->equalTo(true)
        )->will($this->returnSelf());
        $composerInstaller->expects($this->once())->method('setOptimizeAutoloader')->with(
            $this->equalTo(true)
        )->will($this->returnSelf());

        $composerInstaller->expects($this->once())->method('run')->will($this->returnValue(true));
        return $composerInstaller;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|PackageInterface
     */
    protected function createPackageMock()
    {
        return $this->getMock('Composer\Package\PackageInterface');
    }
}
