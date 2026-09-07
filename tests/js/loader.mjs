// Lets `node --test` load the app's source unmodified. Vite allows extensionless
// relative imports (e.g. `./uuid`); Node ESM does not, so we retry with `.js`.
export async function resolve(specifier, context, nextResolve) {
  try {
    return await nextResolve(specifier, context);
  } catch (e) {
    if (specifier.startsWith('.') && !/\.[mc]?js$/.test(specifier)) {
      return await nextResolve(specifier + '.js', context);
    }
    throw e;
  }
}
