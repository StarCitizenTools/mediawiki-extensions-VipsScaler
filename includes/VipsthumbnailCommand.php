<?php
/**
 * PHP wrapper class for VIPS under MediaWiki
 *
 * Copyright © Bryan Tong Minh, 2011
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 * @file
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\VipsScaler;

use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use TempFSFile;

/**
 * Wrapper class around the vipsthumbnail command, useful to chain multiple commands
 * with intermediate .v files
 */
class VipsthumbnailCommand {

	/** Flag to indicate that the output file should be a temporary .v file */
	public const TEMP_OUTPUT = true;

	/** @var string */
	protected $err;

	/** @var string */
	protected $output;

	/** @var string */
	protected $input;

	/** @var bool */
	protected $removeInput;

	/** @var string */
	protected $vipsthumbnail;

	/** @var array */
	protected $args;

	/**
	 * Constructor
	 *
	 * @param string $vipsthumbnail Path to binary
	 * @param array $args Array or arguments
	 */
	public function __construct( string $vipsthumbnail, array $args ) {
		$this->vipsthumbnail = $vipsthumbnail;
		$this->args = $args;
	}

	/**
	 * Set the input and output file of this command
	 *
	 * @param string|VipsthumbnailCommand $input Input file name or an VipsthumbnailCommand object to use the
	 * output of that command
	 * @param string $output Output file name or extension of the temporary file
	 * @param bool $tempOutput Output to a temporary file
	 */
	public function setIO( $input, $output, $tempOutput = false ): void {
		if ( $input instanceof VipsthumbnailCommand ) {
			$this->input = $input->getOutput();
			$this->removeInput = true;
		} else {
			$this->input = $input;
			$this->removeInput = false;
		}
		if ( $tempOutput ) {
			$tmpFile = self::makeTemp( $output );
			$tmpFile->bind( $this );
			$this->output = $tmpFile->getPath();
		} else {
			$this->output = $output;
		}
	}

	/**
	 * Returns the output filename
	 */
	public function getOutput(): string {
		return $this->output;
	}

	/**
	 * Return the output of the command
	 */
	public function getErrorString(): string {
		return $this->err;
	}

	/**
	 * Flatten arguments into "--key=name" array
	 */
	private function makeArguments( array $args ): array {
		$cmdArgs = [];
		foreach ( $args as $key => $value ) {
			$cmdArg = "--$key";
			if ( $value ) {
				$cmdArg .= "=$value";
			}
			array_push( $cmdArgs, $cmdArg );
		}
		return $cmdArgs;
	}

	/**
	 * Constructs the command line array for executing the vipsthumbnail command.
	 */
	private function buildCommand(): array {
		$cmd = [
			$this->vipsthumbnail,
			$this->input,
		];
		# Input arguments
		$cmd = array_merge( $cmd, $this->makeArguments( $this->args ) );
		# Output arguments
		$cmd[] = '-o';
		$cmd[] = $this->output;
		return $cmd;
	}

	/**
	 * Call the vips binary with varargs and returns the return value.
	 */
	public function execute(): int {
		$cmd = $this->buildCommand();

		wfDebug( __METHOD__ . ': running Vips: "' . implode('" "', $cmd ) . '"\n' );

		$result = Shell::command( $cmd )
			->environment( [ 'IM_CONCURRENCY' => '1' ] )
			->limits( [ 'filesize' => 409600 ] )
			->includeStderr()
			->execute();

		$this->err = $result->getStdout();
		$retval = $result->getExitCode();

		# Cleanup temp file
		if ( $retval != 0 && file_exists( $this->output ) ) {
			unlink( $this->output );
		}
		if ( $this->removeInput ) {
			unlink( $this->input );
		}

		return $retval;
	}

	/**
	 * Generate a random, non-existent temporary file with a specified
	 * extension.
	 */
	public static function makeTemp( string $extension ): TempFSFile {
		$tmpFactory = MediaWikiServices::getInstance()->getTempFSFileFactory();
		return $tmpFactory->newTempFSFile( 'vips_', $extension );
	}
}
