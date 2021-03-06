<?php

namespace spec\Bamiz\UseCaseBundle\Container;

use Bamiz\UseCaseBundle\Container\ItemNotFoundException;
use Bamiz\UseCaseBundle\Processor\Input\InputProcessorInterface;
use Bamiz\UseCaseBundle\UseCase\UseCaseInterface;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DelegatingContainerSpec extends ObjectBehavior
{
    public function let(ContainerInterface $symfonyContainer)
    {
        $this->beConstructedWith($symfonyContainer);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType('Bamiz\UseCaseBundle\Container\DelegatingContainer');
    }

    function it_is_a_container_that_accept_references()
    {
        $this->shouldHaveType('Bamiz\UseCaseBundle\Container\ContainerInterface');
        $this->shouldHaveType('Bamiz\UseCaseBundle\Container\ReferenceAcceptingContainerInterface');
    }

    public function it_sets_a_service_reference_in_the_container(
        UseCaseInterface $useCase, InputProcessorInterface $inputProcessor, ContainerInterface $symfonyContainer
    )
    {
        $symfonyContainer->get('bamiz_use_case.some_service')->willReturn($useCase);
        $symfonyContainer->get('bamiz_use_case.input_processor.holy_magic')->willReturn($inputProcessor);

        $this->set('use_case', 'bamiz_use_case.some_service');
        $this->set('input_processor', 'bamiz_use_case.input_processor.holy_magic');

        $this->get('use_case')->shouldBe($useCase);
        $this->get('input_processor')->shouldBe($inputProcessor);
    }

    public function it_throws_an_exception_if_reference_was_not_set()
    {
        $this->shouldThrow(new ItemNotFoundException('Service "no_such_service_here" not found.'))
            ->duringGet('no_such_service_here');
    }

    public function it_throws_an_exception_if_service_was_not_found(ContainerInterface $symfonyContainer)
    {
        $this->set('some_service', 'no_such_service_in_container');
        $symfonyContainer->get('no_such_service_in_container')
            ->willThrow(\Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException::class);

        $this->shouldThrow(new ItemNotFoundException('Reference "some_service" points to a non-existent service "no_such_service_in_container".'))
            ->duringGet('some_service');
    }
}
