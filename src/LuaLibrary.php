<?php
/**
 * Contains the class handling the scribunto lua support.
 *
 * @copyright (C) 2018, Tobias Oetterer, Paderborn University
 * @license       https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License, version 3 (or later)
 *
 * This file is part of the MediaWiki extension BootstrapComponents.
 * The BootstrapComponents extension is free software: you can redistribute it
 * and/or modify it under the terms of the GNU General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * The BootstrapComponents extension is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup       BootstrapComponents
 * @author        Tobias Oetterer
 */

namespace BootstrapComponents;

use MWException;
use ReflectionClass;
use ReflectionException;
use Scribunto_LuaEngine;
use Scribunto_LuaLibraryBase;

/**
 * Class LuaLibrary
 *
 * Class to handle the scribunto lua support.
 *
 * @since 1.1
 */
class LuaLibrary extends Scribunto_LuaLibraryBase {

	/**
	 * @var ApplicationFactory $applicationFactory;
	 */
	private $applicationFactory;

	/**
	 * LuaLibrary constructor.
	 *
	 * @param Scribunto_LuaEngine $engine
	 */
	public function __construct( Scribunto_LuaEngine $engine ) {
		parent::__construct( $engine );
		$this->applicationFactory = ApplicationFactory::getInstance();
	}

	/**
	 * @return array
	 */
	public function register(): array
	{
		$lib = [
			'parse'   => [ $this, 'parse' ],
			'getSkin' => [ $this, 'getSkin' ],
		];

		return $this->getEngine()->registerInterface( __DIR__ . '/../lua/mw.bootstrap.lua', $lib, [] );
	}

	/**
	 * @param string $componentName
	 * @param string $input
	 * @param array  $arguments
	 *
	 * @throws ReflectionException
	 * @throws MWException
	 *
	 * @return string[]
	 *
	 * Note: Please refrain from using Type hints in function signature. Will break tests!
	 */
	public function parse( $componentName, $input, $arguments ): array
	{
		if ( empty( $componentName ) ) {
			return [ wfMessage( 'bootstrap-components-lua-error-no-component' )->text() ];
		}
		$componentLibrary = $this->getApplicationFactory()->getComponentLibrary();
		if ( !in_array( $componentName, $componentLibrary->getRegisteredComponents() ) ) {
			return [ wfMessage( 'bootstrap-components-lua-error-invalid-component', $componentName )->text() ];
		}
		$componentClass = $componentLibrary->getClassFor( $componentName );
		$parserRequest = $this->buildParserRequest( $input, $arguments, $componentName );
		$component = $this->getComponent( $componentClass );

		$parsedComponent = $component->parseComponent( $parserRequest );
		if ( is_array( $parsedComponent ) ) {
			$parsedComponent = $parsedComponent[0];
		}

		return [ $parsedComponent ];
	}

	/**
	 * @throws MWException
	 *
	 * @return string[]
	 */
	public function getSkin(): array
	{
		return [ $this->getApplicationFactory()->getParserOutputHelper( $this->getParser() )->getNameOfActiveSkin() ];
	}

	/**
	 * @param string      $input
	 * @param array       $arguments
	 * @param null|string $component
	 *
	 * @throws MWException
	 *
	 * @return ParserRequest
	 */
	protected function buildParserRequest( string $input, array $arguments, ?string $component = null ): ParserRequest
	{
		// prepare the arguments array
		$parserRequestArguments = $this->processLuaArguments( $arguments );
		array_unshift( $parserRequestArguments, $input );
		array_unshift( $parserRequestArguments, $this->getParser() );

		return ApplicationFactory::getInstance()->getNewParserRequest( $parserRequestArguments, true, $component );
	}

	/**
	 * @param string $componentClass
	 *
	 * @throws MWException
	 * @throws ReflectionException
	 *
	 * @return AbstractComponent
	 */
	protected function getComponent( string $componentClass ): AbstractComponent {

		$objectReflection = new ReflectionClass( $componentClass );
		/** @var AbstractComponent $component */
		$component = $objectReflection->newInstanceArgs(
			[
				$this->getApplicationFactory()->getComponentLibrary(),
				$this->getApplicationFactory()->getParserOutputHelper( $this->getParser() ),
				$this->getApplicationFactory()->getNestingController(),
			]
		);
		return $component;
	}

	/**
	 * @return ApplicationFactory
	 */
	protected function getApplicationFactory(): ApplicationFactory
	{
		return $this->applicationFactory;
	}

	/**
	 * Takes the $arguments passed from lua and pre-processes them: make sure,
	 * we have a sequence array (not associative)
	 *
	 * @param string|array $arguments
	 *
	 * @return array
	 */
	private function processLuaArguments( $arguments ): array
	{
		// make sure, we have an array of parameters
		if ( !is_array( $arguments ) ) {
			$arguments = preg_split( "/(?<=[^\|])\|(?=[^\|])/", $arguments );
		}

		// if $arguments were supplied as key => value pair (aka associative array),
		// we rectify this here
		$processedArguments = [];
		foreach ( $arguments as $key => $value ) {
			$processedArguments[] = $this->processKeyValuePair( $key, $value );
		}

		return $processedArguments;
	}

	/**
	 * @param string $key
	 * @param string $value
	 *
	 * @return string
	 */
	private function processKeyValuePair( string $key, string $value ): string
	{
		if ( is_int( $key ) || preg_match( '/[0-9]+/', $key ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) ) {
			$glue = $key == 'style' ? ';' : ' ';
			return (string) $key . '=' . implode( $glue, $value );
		} else {
			return (string) $key . '=' . (string) $value;
		}
	}
}
