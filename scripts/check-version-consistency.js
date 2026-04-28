#!/usr/bin/env node
/**
 * check-version-consistency.js
 *
 * Verifies that the plugin version is identical across all four canonical
 * locations:
 *   - donations-for-woocommerce-companion.php (Version: header)
 *   - donations-for-woocommerce-companion.php (DFWC_COMPANION_VERSION constant)
 *   - package.json (.version field)
 *   - readme.txt (Stable tag: ...)
 *
 * Optionally, when invoked with a tag argument (e.g., from the release
 * workflow on a `v1.2.3` tag push), verifies the tag matches all four.
 *
 * Usage:
 *   node scripts/check-version-consistency.js               -> validates intra-file consistency
 *   node scripts/check-version-consistency.js v0.7.0         -> also verifies tag matches
 *
 * Exits 0 on consistency, non-zero with an explanatory message on mismatch.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..' );

function read( relativePath ) {
    return fs.readFileSync( path.join( root, relativePath ), 'utf8' );
}

function fail( message ) {
    console.error( `❌  Version inconsistency: ${ message }` );
    process.exit( 1 );
}

const sources = {};

// Plugin file: header `Version: 0.6.6` and `DFWC_COMPANION_VERSION` constant.
{
    const content = read( 'donations-for-woocommerce-companion.php' );
    const headerMatch = content.match( /^\s*\*\s*Version:\s*([0-9][^\s]*)/m );
    const constMatch  = content.match( /define\(\s*'DFWC_COMPANION_VERSION'\s*,\s*'([0-9][^']*)'/ );
    if ( ! headerMatch ) fail( "couldn't find `Version:` header in plugin file" );
    if ( ! constMatch )  fail( "couldn't find DFWC_COMPANION_VERSION constant" );
    sources['plugin header'] = headerMatch[1];
    sources['plugin constant'] = constMatch[1];
}

// package.json
{
    const pkg = JSON.parse( read( 'package.json' ) );
    if ( ! pkg.version ) fail( "package.json has no `version` field" );
    sources['package.json'] = pkg.version;
}

// readme.txt: `Stable tag: 0.6.6`
{
    const content = read( 'readme.txt' );
    const m = content.match( /^Stable tag:\s*([0-9][^\s]*)/m );
    if ( ! m ) fail( "couldn't find `Stable tag:` in readme.txt" );
    sources['readme.txt Stable tag'] = m[1];
}

// All four must agree.
const versions = Object.entries( sources );
const first = versions[0][1];
const mismatched = versions.filter( ( [ , v ] ) => v !== first );
if ( mismatched.length > 0 ) {
    const summary = versions.map( ( [ k, v ] ) => `  ${ k }: ${ v }` ).join( '\n' );
    fail( `versions differ across files:\n${ summary }` );
}

// If a tag argument was provided, it must match.
const tagArg = process.argv[2];
if ( tagArg ) {
    const tagVersion = tagArg.replace( /^v/, '' );
    if ( tagVersion !== first ) {
        fail( `tag ${ tagArg } (parsed as ${ tagVersion }) does not match files at ${ first }` );
    }
    console.log( `✓  Tag ${ tagArg } matches all four canonical version sources at ${ first }` );
} else {
    console.log( `✓  All four canonical version sources agree at ${ first }` );
}

process.exit( 0 );
