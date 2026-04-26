/**
 * Phase F.6 — produce dist/donations-for-woocommerce-companion.zip from the
 * current working tree, honoring .distignore so dev/test files don't ship.
 *
 * Usage: `npm run build:zip`
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
'use strict';

const fs = require( 'node:fs' );
const fsp = require( 'node:fs/promises' );
const path = require( 'node:path' );
const archiver = require( 'archiver' );

const ROOT = path.resolve( __dirname, '..' );
const SLUG = 'donations-for-woocommerce-companion';
const DIST_DIR = path.join( ROOT, 'dist' );
const OUT_PATH = path.join( DIST_DIR, `${ SLUG }.zip` );

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );

async function main() {
	const ignores = await loadDistIgnore();
	await fsp.mkdir( DIST_DIR, { recursive: true } );

	if ( fs.existsSync( OUT_PATH ) ) {
		await fsp.unlink( OUT_PATH );
	}

	const output = fs.createWriteStream( OUT_PATH );
	const archive = archiver( 'zip', { zlib: { level: 9 } } );

	const closed = new Promise( ( resolve, reject ) => {
		output.on( 'close', resolve );
		output.on( 'error', reject );
		archive.on( 'error', reject );
		archive.on( 'warning', ( err ) => {
			if ( err.code === 'ENOENT' ) { console.warn( 'archive warning:', err.message ); }
			else { reject( err ); }
		} );
	} );

	archive.pipe( output );
	const files = await collectFiles( ROOT, '', ignores );
	for ( const rel of files ) {
		const abs = path.join( ROOT, rel );
		archive.file( abs, { name: `${ SLUG }/${ rel.split( path.sep ).join( '/' ) }` } );
	}
	await archive.finalize();
	await closed;

	const sizeKb = Math.round( ( fs.statSync( OUT_PATH ).size / 1024 ) * 10 ) / 10;
	console.log( `\nBuilt ${ OUT_PATH }` );
	console.log( `${ files.length } files, ${ sizeKb } KB` );

	// Spot-check: verify no dev artifacts slipped in.
	const forbidden = [ 'tests/', 'node_modules/', '.wolf/', '.claude/', 'plans/', '.git/', '.github/' ];
	const leaks = files.filter( ( f ) => forbidden.some( ( p ) => f.replace( /\\/g, '/' ).startsWith( p ) ) );
	if ( leaks.length ) {
		console.error( '\nERROR: dev files leaked into the zip:' );
		leaks.forEach( ( f ) => console.error( '  ' + f ) );
		process.exit( 1 );
	}
}

async function loadDistIgnore() {
	const distignorePath = path.join( ROOT, '.distignore' );
	const raw = await fsp.readFile( distignorePath, 'utf8' );
	return raw
		.split( /\r?\n/ )
		.map( ( s ) => s.trim() )
		.filter( ( s ) => s && ! s.startsWith( '#' ) );
}

/**
 * Walk the working tree, returning a sorted list of relative file paths
 * that pass the .distignore filter.
 */
async function collectFiles( root, relDir, ignores ) {
	const out = [];
	const entries = await fsp.readdir( path.join( root, relDir ), { withFileTypes: true } );
	for ( const entry of entries ) {
		const rel = relDir ? path.join( relDir, entry.name ) : entry.name;
		if ( isIgnored( rel, entry.isDirectory(), ignores ) ) { continue; }
		if ( entry.isDirectory() ) {
			out.push( ...await collectFiles( root, rel, ignores ) );
		} else if ( entry.isFile() ) {
			out.push( rel );
		}
	}
	return out.sort();
}

function isIgnored( relPath, isDir, ignores ) {
	const normalized = relPath.split( path.sep ).join( '/' );
	for ( const pattern of ignores ) {
		const p = pattern.replace( /^\/+|\/+$/g, '' );
		if ( ! p ) { continue; }
		if ( normalized === p ) { return true; }
		if ( normalized.startsWith( p + '/' ) ) { return true; }
		if ( ! isDir && normalized.endsWith( '/' + p ) ) { return true; }
		// Wildcard match for *.zip etc.
		if ( p.includes( '*' ) ) {
			const re = new RegExp( '^' + p.split( '*' ).map( escapeRe ).join( '.*' ) + '$' );
			if ( re.test( normalized ) || re.test( path.basename( normalized ) ) ) { return true; }
		}
	}
	return false;
}

function escapeRe( s ) { return s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ); }
