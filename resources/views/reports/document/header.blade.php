{{--
    The CSPC institutional header.

    One partial, used by the on-screen preview, the print view and the PDF, so
    every formal report opens the same way. Deliberately plain: a document
    masthead, not an interface element. No form/document code appears here.
--}}
<header class="doc-header">
    <img
        class="doc-header-seal"
        src="{{ $sealSrc ?? asset('images/cspc-seal-document.png') }}"
        alt="Camarines Sur Polytechnic Colleges"
    >

    <div class="doc-header-identity">
        <span class="doc-header-republic">Republic of the Philippines</span>
        <strong class="doc-header-institution">CAMARINES SUR POLYTECHNIC COLLEGES</strong>
        <span class="doc-header-address">Nabua, Camarines Sur</span>
    </div>
</header>
