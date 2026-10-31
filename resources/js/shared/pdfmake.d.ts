declare module 'pdfmake/build/pdfmake' {
    const pdfMake: {
        addVirtualFileSystem(vfs: Record<string, string>): void;
        createPdf(definition: Record<string, unknown>): { download(name?: string): Promise<void>; open(): void };
    };
    export default pdfMake;
}
declare module 'pdfmake/build/vfs_fonts' {
    const vfs: Record<string, string>;
    export default vfs;
}
