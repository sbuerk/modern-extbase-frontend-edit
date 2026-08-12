/**
 * The three ways a documentation screenshot can be wrong without a single pixel
 * being out of date.
 *
 * Comparing an image against the surface answers "is this shot current". It
 * cannot answer "is this shot *used*", and the failures that live in that gap
 * are quiet ones:
 *
 * - A shot is removed from `screenshots.config.ts`, its image stays behind, and
 *   the repository carries a file nothing produces and nothing checks.
 * - An image is generated and committed but no chapter embeds it, so the work
 *   went into a file no reader ever sees.
 * - A chapter embeds a path that does not exist. The renderer only **warns**
 *   about that and still exits zero — the same trap `checkRstSectionAdornments`
 *   was written for — so `renderDocumentation` does not catch it either.
 *
 * All three are answered by reading files, with no browser and no instance, so
 * they are cheap enough to run on every shot check.
 */
import * as fs from 'node:fs';
import * as path from 'node:path';

const documentationRoot = path.resolve(__dirname, '../../../Documentation');
const imageRoot = path.join(documentationRoot, 'files/images');

/**
 * A `figure::` or `image::` directive, as reStructuredText writes it.
 *
 * The path is absolute against the documentation root, which is what the
 * renderer resolves `/files/images/…` against. A relative one would be resolved
 * against the including file, and this repository writes none — the check below
 * reports one as unresolvable rather than guessing, which is the safe direction.
 */
const directive = /^\s*\.\.\s{1,2}(?:figure|image)::\s*(\S+)\s*$/gm;

function filesBelow(directoryPath: string): string[] {
    if (!fs.existsSync(directoryPath)) {
        return [];
    }

    return fs.readdirSync(directoryPath, { withFileTypes: true }).flatMap((entry): string[] => {
        const full = path.join(directoryPath, entry.name);

        return entry.isDirectory() ? filesBelow(full) : [full];
    });
}

/**
 * Every image the repository carries, as a path below `files/images/`.
 */
export function committedImages(): string[] {
    return filesBelow(imageRoot)
        .map((file): string => path.relative(imageRoot, file))
        .sort();
}

export interface Embed {
    readonly file: string;
    readonly line: number;
    readonly target: string;
}

/**
 * Every image directive of every chapter.
 */
export function embeds(): Embed[] {
    const found: Embed[] = [];
    for (const file of filesBelow(documentationRoot).filter((name): boolean => name.endsWith('.rst'))) {
        const content = fs.readFileSync(file, 'utf8');
        for (const match of content.matchAll(directive)) {
            found.push({
                file: path.relative(documentationRoot, file),
                line: content.slice(0, match.index).split('\n').length,
                target: match[1],
            });
        }
    }

    return found;
}

/**
 * The embeds whose target is not a file, reported as readable lines.
 */
export function unresolvableEmbeds(): string[] {
    return embeds()
        .filter((embed): boolean => !fs.existsSync(path.join(documentationRoot, embed.target.replace(/^\//, ''))))
        .map((embed): string => `${embed.file}:${embed.line} embeds "${embed.target}", which does not exist`);
}

/**
 * The images no chapter embeds.
 */
export function unusedImages(): string[] {
    const embedded = new Set(
        embeds()
            .map((embed): string => embed.target.replace(/^\/files\/images\//, ''))
    );

    return committedImages().filter((image): boolean => !embedded.has(image));
}

/**
 * The images no shot produces.
 *
 * Not every image below `files/images/` has to come from the generator — a hand
 * drawn diagram is a perfectly good documentation image — so this returns the
 * orphans of the generator's own output directory only, and leaves the rest
 * alone. The directory is derived from the configured shots rather than named
 * here, so adding a second one does not need this file edited.
 */
export function orphanedShotImages(configuredOutputs: readonly string[]): string[] {
    const generatedDirectories = new Set(configuredOutputs.map((output): string => path.dirname(output)));
    const configured = new Set(configuredOutputs);

    return committedImages()
        .filter((image): boolean => generatedDirectories.has(path.dirname(image)))
        .filter((image): boolean => !configured.has(image));
}
