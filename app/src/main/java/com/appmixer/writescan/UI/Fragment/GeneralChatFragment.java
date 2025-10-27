package com.appmixer.writescan.UI.Fragment;

import android.Manifest;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.KeyEvent;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.EditorInfo;
import android.view.inputmethod.InputMethodManager;
import android.widget.EditText;
import android.widget.ProgressBar;
import android.widget.Toast;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.core.content.ContextCompat;
import androidx.fragment.app.Fragment;
import androidx.lifecycle.ViewModelProvider;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.appmixer.writescan.Adapter.GeneralChatAdapter;
import com.appmixer.writescan.R;
import com.appmixer.writescan.viewmodel.GeneralChatViewModel;
import com.google.android.material.card.MaterialCardView;

import java.util.Objects;

public class GeneralChatFragment extends Fragment {

    private static final String PERMISSION_STORAGE = Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
            ? Manifest.permission.READ_MEDIA_IMAGES
            : Manifest.permission.READ_EXTERNAL_STORAGE;

    private GeneralChatViewModel viewModel;
    private GeneralChatAdapter adapter;
    private RecyclerView recyclerView;
    private EditText messageInput;
    private ProgressBar sendProgress;
    private MaterialCardView sendButton;
    private View attachButton;

    private final ActivityResultLauncher<String> pickImageLauncher =
            registerForActivityResult(new ActivityResultContracts.GetContent(), this::handleImagePicked);

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container, @Nullable Bundle savedInstanceState) {
        return inflater.inflate(R.layout.fragment_general_chat_view, container, false);
    }

    @Override
    public void onViewCreated(@NonNull View view, @Nullable Bundle savedInstanceState) {
        super.onViewCreated(view, savedInstanceState);
        viewModel = new ViewModelProvider(this).get(GeneralChatViewModel.class);

        recyclerView = view.findViewById(R.id.rv_chats);
        messageInput = view.findViewById(R.id.et_message);
        sendButton = view.findViewById(R.id.btn_send);
        sendProgress = view.findViewById(R.id.send_progress);
        attachButton = view.findViewById(R.id.btn_upload);

        setupRecycler();
        setupListeners();
        observeViewModel();
        viewModel.refresh();

        messageInput.post(() -> {
            messageInput.requestFocus();
            showKeyboard();
        });
    }

    private void setupRecycler() {
        adapter = new GeneralChatAdapter();
        adapter.setMessageActionListener(new GeneralChatAdapter.MessageActionListener() {
            @Override
            public void onCopy(String text) {
                copyToClipboard(text);
            }

            @Override
            public void onShare(String text) {
                shareText(text);
            }
        });
        LinearLayoutManager layoutManager = new LinearLayoutManager(getContext());
        layoutManager.setStackFromEnd(true);
        recyclerView.setLayoutManager(layoutManager);
        recyclerView.setAdapter(adapter);

        recyclerView.addOnLayoutChangeListener((v, left, top, right, bottom, oldLeft, oldTop, oldRight, oldBottom) -> {
            if (bottom < oldBottom) {
                recyclerView.postDelayed(() -> {
                    int last = adapter.getItemCount() - 1;
                    if (last >= 0) {
                        recyclerView.scrollToPosition(last);
                    }
                }, 50);
            }
        });
    }

    private void setupListeners() {
        if (attachButton != null) {
            attachButton.setOnClickListener(v -> requestImage());
        }

        sendButton.setOnClickListener(v -> triggerSend(null));
        messageInput.setOnEditorActionListener((textView, actionId, event) -> {
            if (actionId == EditorInfo.IME_ACTION_SEND
                    || (event != null && event.getKeyCode() == KeyEvent.KEYCODE_ENTER)) {
                triggerSend(null);
                return true;
            }
            return false;
        });
    }

    private void observeViewModel() {
        viewModel.observeMessages().observe(getViewLifecycleOwner(), messages -> {
            adapter.submitList(messages);
            if (messages != null && !messages.isEmpty()) {
                recyclerView.post(() -> recyclerView.scrollToPosition(messages.size() - 1));
            }
        });

        viewModel.getIsSending().observe(getViewLifecycleOwner(), sending -> {
            boolean busy = sending != null && sending;
            sendProgress.setVisibility(busy ? View.VISIBLE : View.GONE);
            sendButton.setEnabled(!busy);
        });

        viewModel.getToastMessage().observe(getViewLifecycleOwner(), message -> {
            if (!TextUtils.isEmpty(message) && getContext() != null) {
                Toast.makeText(getContext(), message, Toast.LENGTH_SHORT).show();
            }
        });
    }

    private void triggerSend(@Nullable Uri imageUri) {
        String prompt = messageInput.getText() != null ? messageInput.getText().toString().trim() : "";
        if (prompt.isEmpty() && imageUri == null) {
            Toast.makeText(getContext(), R.string.ask_a_question, Toast.LENGTH_SHORT).show();
            return;
        }
        hideKeyboard();
        if (imageUri == null) {
            messageInput.setText("");
        }
        viewModel.sendMessage(prompt, imageUri);
    }

    private void requestImage() {
        if (getContext() == null) {
            return;
        }
        if (ContextCompat.checkSelfPermission(getContext(), PERMISSION_STORAGE)
                == PackageManager.PERMISSION_GRANTED) {
            pickImageLauncher.launch("image/*");
        } else {
            requestPermissions(new String[]{PERMISSION_STORAGE}, 1001);
        }
    }

    private void handleImagePicked(Uri uri) {
        if (uri == null) {
            Toast.makeText(getContext(), R.string.no_image_selected, Toast.LENGTH_SHORT).show();
            return;
        }
        triggerSend(uri);
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions, @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == 1001) {
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                pickImageLauncher.launch("image/*");
            } else {
                Toast.makeText(getContext(), R.string.permission_required, Toast.LENGTH_SHORT).show();
            }
        }
    }

    private void hideKeyboard() {
        View view = getView();
        if (view == null || getContext() == null) {
            return;
        }
        InputMethodManager imm = (InputMethodManager) getContext().getSystemService(Context.INPUT_METHOD_SERVICE);
        if (imm != null) {
            imm.hideSoftInputFromWindow(view.getWindowToken(), 0);
        }
    }

    private void showKeyboard() {
        if (getContext() == null) {
            return;
        }
        InputMethodManager imm = (InputMethodManager) getContext().getSystemService(Context.INPUT_METHOD_SERVICE);
        if (imm != null) {
            imm.showSoftInput(messageInput, InputMethodManager.SHOW_IMPLICIT);
        }
    }

    private void copyToClipboard(String text) {
        if (getContext() == null) {
            return;
        }
        ClipboardManager clipboard = (ClipboardManager) getContext().getSystemService(Context.CLIPBOARD_SERVICE);
        if (clipboard != null) {
            clipboard.setPrimaryClip(ClipData.newPlainText("chat", text));
            Toast.makeText(getContext(), R.string.text_copied, Toast.LENGTH_SHORT).show();
        }
    }

    private void shareText(String text) {
        if (getContext() == null) {
            return;
        }
        Intent shareIntent = new Intent(Intent.ACTION_SEND);
        shareIntent.setType("text/plain");
        shareIntent.putExtra(Intent.EXTRA_TEXT, text);
        startActivity(Intent.createChooser(shareIntent, getString(R.string.share_apps)));
    }
}
