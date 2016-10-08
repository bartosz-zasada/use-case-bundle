<?php

namespace Lamudi\UseCaseBundle\DependencyInjection;

use Doctrine\Common\Annotations\AnnotationReader;
use Lamudi\UseCaseBundle\Annotation\InputProcessor as InputAnnotation;
use Lamudi\UseCaseBundle\Annotation\UseCase as UseCaseAnnotation;
use Lamudi\UseCaseBundle\Container\ReferenceAcceptingContainerInterface;
use Lamudi\UseCaseBundle\UseCase\RequestResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class UseCaseCompilerPass implements CompilerPassInterface
{
    /**
     * @var AnnotationReader
     */
    private $annotationReader;

    /**
     * @var RequestResolver
     */
    private $requestResolver;

    /**
     * @param AnnotationReader $annotationReader
     * @param RequestResolver  $requestResolver
     */
    public function __construct(AnnotationReader $annotationReader = null, RequestResolver $requestResolver = null)
    {
        $this->annotationReader = $annotationReader ?: new AnnotationReader();
        $this->requestResolver = $requestResolver ?: new RequestResolver();
    }

    /**
     * You can modify the container here before it is dumped to PHP code.
     *
     * @param ContainerBuilder $container
     *
     * @throws \Exception
     * @api
     */
    public function process(ContainerBuilder $container)
    {
        if (!$container->has('lamudi_use_case.executor')) {
            return;
        }

        $this->addInputProcessorsToContainer($container);
        $this->addResponseProcessorsToContainer($container);
        $this->addUseCasesToContainer($container);
        $this->addContextsToResolver($container);
    }

    /**
     * @param ContainerBuilder $container
     *
     * @return array
     * @throws \Exception
     */
    private function addUseCasesToContainer(ContainerBuilder $container)
    {
        $executorDefinition = $container->findDefinition('lamudi_use_case.executor');
        $useCaseContainerDefinition = $container->findDefinition('lamudi_use_case.container.use_case');
        $services = $container->getDefinitions();

        foreach ($services as $id => $serviceDefinition) {
            $serviceClass = $serviceDefinition->getClass();
            if (!class_exists($serviceClass)) {
                continue;
            }

            $useCaseReflection = new \ReflectionClass($serviceClass);
            try {
                $annotations = $this->annotationReader->getClassAnnotations($useCaseReflection);
            } catch (\InvalidArgumentException $e) {
                throw new \Exception(sprintf('Could not load annotations for class %s: %s', $serviceClass, $e->getMessage()));
            }

            foreach ($annotations as $annotation) {
                if ($annotation instanceof UseCaseAnnotation && $this->validateUseCase($useCaseReflection)) {
                    $this->registerUseCase(
                        $id, $serviceClass, $annotation, $annotations, $executorDefinition, $useCaseContainerDefinition
                    );
                }
            }
        }
    }

    /**
     * @param ContainerBuilder $containerBuilder
     */
    private function addInputProcessorsToContainer(ContainerBuilder $containerBuilder)
    {
        $processorContainerDefinition = $containerBuilder->findDefinition('lamudi_use_case.container.input_processor');
        $inputProcessors = $containerBuilder->findTaggedServiceIds('use_case_input_processor');
        foreach ($inputProcessors as $id => $tags) {
            foreach ($tags as $attributes) {
                if ($this->containerAcceptsReferences($processorContainerDefinition)) {
                    $processorContainerDefinition->addMethodCall('set', [$attributes['alias'], $id]);
                } else {
                    $processorContainerDefinition->addMethodCall('set', [$attributes['alias'], new Reference($id)]);
                }
            }
        }
    }

    /**
     * @param ContainerBuilder $containerBuilder
     */
    private function addResponseProcessorsToContainer(ContainerBuilder $containerBuilder)
    {
        $processorContainerDefinition = $containerBuilder->findDefinition(
            'lamudi_use_case.container.response_processor'
        );
        $responseProcessors = $containerBuilder->findTaggedServiceIds('use_case_response_processor');

        foreach ($responseProcessors as $id => $tags) {
            foreach ($tags as $attributes) {
                if ($this->containerAcceptsReferences($processorContainerDefinition)) {
                    $processorContainerDefinition->addMethodCall('set', [$attributes['alias'], $id]);
                } else {
                    $processorContainerDefinition->addMethodCall('set', [$attributes['alias'], new Reference($id)]);
                }
            }
        }
    }

    /**
     * @param ContainerBuilder $containerBuilder
     */
    private function addContextsToResolver(ContainerBuilder $containerBuilder)
    {
        $resolverDefinition = $containerBuilder->findDefinition('lamudi_use_case.context_resolver');
        $defaultContextName = $containerBuilder->getParameter('lamudi_use_case.default_context');
        $contexts = (array)$containerBuilder->getParameter('lamudi_use_case.contexts');

        $resolverDefinition->addMethodCall('setDefaultContextName', [$defaultContextName]);
        foreach ($contexts as $name => $context) {
            $input = isset($context['input']) ? $context['input'] : null;
            $response = isset($context['response']) ? $context['response'] : null;
            $resolverDefinition->addMethodCall('addContextDefinition', [$name, $input, $response]);
        }
    }

    /**
     * @param \ReflectionClass $useCase
     *
     * @return bool
     * @throws InvalidUseCase
     */
    private function validateUseCase($useCase)
    {
        if ($useCase->hasMethod('execute')) {
            return true;
        } else {
            throw new InvalidUseCase(sprintf(
                'Class "%s" has been annotated as a Use Case, but does not contain execute() method.', $useCase->getName()
            ));
        }
    }

    /**
     * @param string            $serviceId
     * @param string            $serviceClass
     * @param UseCaseAnnotation $useCaseAnnotation
     * @param array             $annotations
     * @param Definition        $executorDefinition
     * @param Definition        $containerDefinition
     *
     * @throws \Lamudi\UseCaseBundle\UseCase\RequestClassNotFoundException
     */
    private function registerUseCase($serviceId, $serviceClass, $useCaseAnnotation, $annotations, $executorDefinition, $containerDefinition)
    {
        $useCaseName = $useCaseAnnotation->getName() ?: $this->fqnToUseCaseName($serviceClass);

        $this->addUseCaseToUseCaseContainer($containerDefinition, $useCaseName, $serviceId);
        $this->assignInputProcessorToUseCase($executorDefinition, $useCaseName, $useCaseAnnotation, $annotations);
        $this->assignResponseProcessorToUseCase($executorDefinition, $useCaseName, $useCaseAnnotation);
        $this->resolveUseCaseRequestClassName($executorDefinition, $useCaseName, $serviceClass);
    }

    /**
     * @param Definition $containerDefinition
     *
     * @return bool
     */
    private function containerAcceptsReferences($containerDefinition)
    {
        $interfaces = class_implements($containerDefinition->getClass());
        if (is_array($interfaces)) {
            return in_array(ReferenceAcceptingContainerInterface::class, $interfaces);
        } else {
            return false;
        }
    }

    /**
     * @param string $fqn
     *
     * @return string
     */
    private function fqnToUseCaseName($fqn)
    {
        $unqualifiedName = substr($fqn, strrpos($fqn, '\\') + 1);
        return ltrim(strtolower(preg_replace('/[A-Z0-9]/', '_$0', $unqualifiedName)), '_');
    }

    /**
     * @param Definition $containerDefinition
     * @param string     $useCaseName
     * @param string     $serviceId
     */
    private function addUseCaseToUseCaseContainer($containerDefinition, $useCaseName, $serviceId)
    {
        if ($this->containerAcceptsReferences($containerDefinition)) {
            $containerDefinition->addMethodCall('set', [$useCaseName, $serviceId]);
        } else {
            $containerDefinition->addMethodCall('set', [$useCaseName, new Reference($serviceId)]);
        }
    }

    /**
     * @param Definition        $executorDefinition
     * @param string            $useCaseName
     * @param UseCaseAnnotation $useCaseAnnotation
     * @param array             $annotations
     */
    private function assignInputProcessorToUseCase($executorDefinition, $useCaseName, $useCaseAnnotation, $annotations)
    {
        $useCaseConfig = $useCaseAnnotation->getConfiguration();
        foreach ($annotations as $annotation) {
            if ($annotation instanceof InputAnnotation) {
                $useCaseConfig->addInputProcessor($annotation->getName(), $annotation->getOptions());
            }
        }

        if ($useCaseConfig->getInputProcessorName()) {
            $executorDefinition->addMethodCall(
                'assignInputProcessor',
                [$useCaseName, $useCaseConfig->getInputProcessorName(), $useCaseConfig->getInputProcessorOptions()]
            );
        }
    }

    /**
     * @param Definition        $executorDefinition
     * @param string            $useCaseName
     * @param UseCaseAnnotation $annotation
     */
    private function assignResponseProcessorToUseCase($executorDefinition, $useCaseName, $annotation)
    {
        $useCaseConfig = $annotation->getConfiguration();

        if ($useCaseConfig->getResponseProcessorName()) {
            $executorDefinition->addMethodCall(
                'assignResponseProcessor',
                [$useCaseName, $useCaseConfig->getResponseProcessorName(), $useCaseConfig->getResponseProcessorOptions()]
            );
        }
    }

    /**
     * @param Definition $executorDefinition
     * @param string     $useCaseName
     * @param string     $useCaseClassName
     *
     * @throws \Lamudi\UseCaseBundle\UseCase\RequestClassNotFoundException
     */
    private function resolveUseCaseRequestClassName($executorDefinition, $useCaseName, $useCaseClassName)
    {
        $requestClassName = $this->requestResolver->resolve($useCaseClassName);
        $executorDefinition->addMethodCall('assignRequestClass', [$useCaseName, $requestClassName]);
    }
}
