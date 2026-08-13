/**
 * The profile image, uploaded and removed through a real browser.
 *
 * This is the one part of the feature whose transport a functional test can
 * only approximate. `ProfileImageUploadTest` builds the multipart request
 * itself and states the parse result PHP would have produced from it; here the
 * browser picks a file, `FormData` serialises it, `fetch` sends it without a
 * `Content-Type` header so that the boundary is the browser's, and PHP parses
 * it into `$_FILES`. Everything between the file picker and `sys_file` is under
 * test exactly once, and it is here.
 *
 * Both specs assert the write twice, as every spec of this suite does: the
 * **reloaded page** has to serve the new image, which is the only proof that the
 * server stored it rather than the component having redrawn itself, and the
 * file itself has to be fetchable — or, after a removal, gone.
 */
import { expect, test } from '../fixtures';
import { ProfileEditPage } from '../Support/profileEditPage';

/**
 * The name an uploaded portrait ends up with: the client file name, plus the
 * random suffix `addRandomSuffix` appends to it.
 *
 * The suffix is why the URL is read off the response rather than assembled —
 * what a file is called after it has been stored is not derivable from what it
 * was called before.
 */
const storedImageUrl = /^\/fileadmin\/user_upload\/profiles\/profile-image-[0-9a-f]{16}\.png$/;

/**
 * The size of `Tests/Functional/Fixtures/Files/profile-image.png` in bytes.
 */
const fixtureImageSize = 72;

test.describe('The profile image', (): void => {
    test('the picker says which of the two things it will do', async ({
        page,
        loginAs,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        // Choosing a file *is* the write — there is no apply step after it — so
        // the control has to state which write it is. "Choose image" beside a
        // stored portrait would understate what pressing it costs.
        await expect(surface.imagePicker).toHaveText('Choose image');

        await surface.uploadImage();
        await expect(surface.enhancedImage).toBeVisible();
        await expect(surface.imagePicker).toHaveText('Replace image');

        await surface.removeImage();
        await expect(surface.imagePicker).toHaveText('Choose image');
    });

    test('an image uploaded in the browser is served by the server after a reload', async ({
        page,
        loginAs,
        pageErrors,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await expect(surface.servedImage).toHaveCount(0);
        await expect(surface.enhancedImage).toHaveCount(0);

        const response = await surface.uploadImage();
        expect(response.status()).toBe(200);

        await expect(surface.enhancedImage).toHaveAttribute('src', storedImageUrl);
        const source = await surface.enhancedImage.getAttribute('src');
        expect(source).not.toBeNull();

        const reloaded = await page.reload();
        await surface.waitForEnhancement();

        /*
         * The markup the server sent, not the surface the component drew - read
         * from the response body rather than from the DOM.
         *
         * It used to be a DOM assertion, and it cannot be one any more: the
         * element renders into the light DOM now and removes the server rendered
         * view when it takes over, so by the time the page has enhanced there is
         * nothing left of the `Profile/Image` partial to look at. Under a shadow
         * root the same markup stayed in the document, unrendered, and could be
         * queried.
         *
         * The response body is a better answer to the same question anyway. It
         * is what the server actually sent, uncontaminated by anything the
         * component did afterwards, which is precisely what this assertion has
         * always claimed to check.
         */
        expect(await reloaded?.text()).toContain(`src="${source ?? ''}"`);
        // And the attribute the document travels in, which is what the surface
        // is rebuilt from after the reload.
        await expect(surface.element).toHaveAttribute('data-profile', new RegExp(`"publicUrl":"${source}"`));

        const served = await page.request.get(source ?? '');
        expect(served.status()).toBe(200);
        expect((await served.body()).length).toBe(fixtureImageSize);

        expect(pageErrors).toEqual([]);
    });

    test('a stored image can be removed again, and the file goes with it', async ({
        page,
        loginAs,
        pageErrors,
    }): Promise<void> => {
        await loginAs('owner');
        const surface = new ProfileEditPage(page);
        await surface.open();
        await surface.waitForEnhancement();

        await surface.uploadImage();
        await expect(surface.enhancedImage).toHaveAttribute('src', storedImageUrl);
        const source = await surface.enhancedImage.getAttribute('src');

        const response = await surface.removeImage();
        expect(response.status()).toBe(200);
        await expect(surface.enhancedImage).toHaveCount(0);

        await page.reload();
        await surface.waitForEnhancement();

        await expect(surface.servedImage).toHaveCount(0);
        await expect(surface.element).toHaveAttribute('data-profile', /"image":null/);

        // The file is gone as well, because nothing else referenced it. A `200`
        // here would mean the record lost its image and the storage kept the
        // file — the accumulation the explicit cleanup exists to prevent.
        const served = await page.request.get(source ?? '');
        expect(served.status()).toBe(404);

        expect(pageErrors).toEqual([]);
    });
});
